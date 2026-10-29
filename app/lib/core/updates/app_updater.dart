import 'dart:async';
import 'dart:convert';
import 'dart:io';

import 'package:crypto/crypto.dart';
import 'package:flutter/foundation.dart';
import 'package:flutter/services.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:package_info_plus/package_info_plus.dart';

import '../config.dart';
import '../providers.dart';

/// Keeps the app up to date without asking: the server lists the newest build per
/// platform at /apps/latest.json and the app installs a higher build number by itself.
///
/// * Android: native code (AppUpdater.kt) does the work in WorkManager, so it also
///   runs while the app is closed, and installs while the app is in the background.
/// * Windows: [WindowsUpdater] downloads the new build and swaps it in while the
///   window is hidden in the tray.
///
/// Release builds only: a debug build must never be replaced by a release.
abstract class AppUpdater {
  Future<void> start();

  /// Android only: false until "Install unknown apps" is allowed for MbunieMail.
  /// Until then each update asks for one tap.
  Future<bool> canInstallSilently() async => true;
  Future<void> openInstallSettings() async {}
}

class NoUpdater extends AppUpdater {
  @override
  Future<void> start() async {}
}

Uri manifestUrl(AppConfig config) =>
    config.apiBaseUrl.resolve('/apps/latest.json');

class AndroidUpdater extends AppUpdater {
  AndroidUpdater(this._config);

  final AppConfig _config;
  static const _channel = MethodChannel('mbuniemail/updates');

  @override
  Future<void> start() async {
    try {
      await _channel.invokeMethod('configure', {
        'manifestUrl': manifestUrl(_config).toString(),
      });
    } on PlatformException catch (e) {
      debugPrint('Auto-update unavailable: $e');
    }
  }

  @override
  Future<bool> canInstallSilently() async =>
      await _channel.invokeMethod<bool>('canInstallSilently') ?? false;

  @override
  Future<void> openInstallSettings() =>
      _channel.invokeMethod('openInstallSettings');
}

/// One build offered by /apps/latest.json.
class AvailableBuild {
  const AvailableBuild({
    required this.build,
    required this.url,
    required this.sha256,
  });

  final int build;
  final Uri url;
  final String sha256;

  static AvailableBuild? fromManifest(Map<String, dynamic> j, String platform) {
    final p = j[platform];
    if (p is! Map) return null;
    final build = p['build'];
    final url = Uri.tryParse('${p['url'] ?? ''}');
    final sha = '${p['sha256'] ?? ''}'.toLowerCase();
    if (build is! int || url == null || sha.length != 64) return null;
    return AvailableBuild(build: build, url: url, sha256: sha);
  }
}

/// Windows: downloads a newer build (a zip of the Release folder) to %TEMP%, checks
/// its SHA-256, and when the window has been hidden in the tray for a while, hands
/// over to a small PowerShell script that waits for this process to exit, copies the
/// new files over the install folder and starts MbunieMail again in the tray.
class WindowsUpdater extends AppUpdater {
  WindowsUpdater(this._config);

  final AppConfig _config;
  Timer? _timer;
  Timer? _whileHidden;
  File? _ready;

  /// Whether the window is hidden in the tray, as last reported by the shell.
  bool hidden = false;

  /// Set by the desktop shell: asks the window itself, in case it was shown by
  /// something the shell did not see (such as opening MbunieMail a second time).
  Future<bool> Function()? isWindowVisible;

  /// Set by the desktop shell: closes the app so the update can replace its files.
  Future<void> Function()? exitApp;

  static const _checkEvery = Duration(hours: 6);
  static const _hiddenFor = Duration(minutes: 5);

  @override
  Future<void> start() async {
    _timer ??= Timer.periodic(_checkEvery, (_) => check());
    unawaited(Future.delayed(const Duration(minutes: 1), check));
  }

  /// Called by the desktop shell whenever the window is hidden or shown.
  void windowHidden(bool isHidden) {
    hidden = isHidden;
    _whileHidden?.cancel();
    if (isHidden && _ready != null) {
      _whileHidden = Timer(_hiddenFor, () async {
        if (await isWindowVisible?.call() ?? false) return;
        await apply(relaunch: true);
      });
    }
  }

  Future<void> check() async {
    try {
      final latest = AvailableBuild.fromManifest(
        await _getJson(manifestUrl(_config)),
        'windows',
      );
      final info = await PackageInfo.fromPlatform();
      final installed = int.tryParse(info.buildNumber) ?? 0;
      if (latest == null || latest.build <= installed) return;
      _ready = await _download(latest);
      if (_ready != null && hidden) windowHidden(true);
    } catch (e) {
      debugPrint('Update check failed: $e');
    }
  }

  /// Installs a downloaded update. Returns false when there is none or the install
  /// folder cannot be written (then the app keeps running unchanged).
  Future<bool> apply({required bool relaunch}) async {
    final zip = _ready;
    if (zip == null || !await zip.exists()) return false;
    final exe = File(Platform.resolvedExecutable);
    final dir = exe.parent;
    if (!await _canWrite(dir)) {
      debugPrint('Update not installed: ${dir.path} is not writable');
      return false;
    }
    final script = File('${zip.parent.path}\\apply-update.ps1');
    await script.writeAsString(_applyScript);
    await Process.start('powershell.exe', [
      '-NoProfile',
      '-ExecutionPolicy',
      'Bypass',
      '-WindowStyle',
      'Hidden',
      '-File',
      script.path,
      '-ProcessId',
      '$pid',
      '-Zip',
      zip.path,
      '-InstallDir',
      dir.path,
      '-Exe',
      exe.path,
      if (relaunch) '-Relaunch',
    ], mode: ProcessStartMode.detached);
    _ready = null;
    await exitApp?.call();
    return true;
  }

  Future<File?> _download(AvailableBuild b) async {
    final dir = Directory('${Directory.systemTemp.path}\\MbunieMail-update');
    await dir.create(recursive: true);
    final zip = File('${dir.path}\\MbunieMail-${b.build}-windows.zip');
    if (await zip.exists() && await _sha256(zip) == b.sha256) return zip;
    await for (final old in dir.list()) {
      if (old is File) await old.delete();
    }
    final part = File('${zip.path}.part');
    final client = HttpClient();
    try {
      final res = await (await client.getUrl(b.url)).close();
      if (res.statusCode != 200) throw HttpException('HTTP ${res.statusCode}');
      await res.pipe(part.openWrite());
    } finally {
      client.close();
    }
    if (await _sha256(part) != b.sha256) {
      await part.delete();
      debugPrint('Downloaded build ${b.build} does not match its SHA-256');
      return null;
    }
    return part.rename(zip.path);
  }

  static Future<String> _sha256(File f) async =>
      (await sha256.bind(f.openRead()).first).toString();

  static Future<Map<String, dynamic>> _getJson(Uri url) async {
    final client = HttpClient()
      ..connectionTimeout = const Duration(seconds: 20);
    try {
      final res = await (await client.getUrl(url)).close();
      if (res.statusCode != 200) throw HttpException('HTTP ${res.statusCode}');
      final body = await res.transform(utf8.decoder).join();
      return (jsonDecode(body) as Map).cast<String, dynamic>();
    } finally {
      client.close();
    }
  }

  static Future<bool> _canWrite(Directory dir) async {
    final probe = File('${dir.path}\\.update-probe');
    try {
      await probe.writeAsString('');
      await probe.delete();
      return true;
    } on FileSystemException {
      return false;
    }
  }

  // Waits for the app to exit, unpacks the new build next to it and swaps it in.
  // Nothing is replaced if the zip does not contain the app's executable.
  static const _applyScript = r'''
param([int]$ProcessId, [string]$Zip, [string]$InstallDir, [string]$Exe, [switch]$Relaunch)
$ErrorActionPreference = 'Stop'
Wait-Process -Id $ProcessId -Timeout 60 -ErrorAction SilentlyContinue
Start-Sleep -Milliseconds 500
$stage = Join-Path (Split-Path $Zip) 'stage'
try {
  if (Test-Path $stage) { Remove-Item $stage -Recurse -Force }
  Expand-Archive -Path $Zip -DestinationPath $stage -Force
  if (Test-Path (Join-Path $stage (Split-Path $Exe -Leaf))) {
    Copy-Item -Path (Join-Path $stage '*') -Destination $InstallDir -Recurse -Force
  }
} finally {
  Remove-Item $stage -Recurse -Force -ErrorAction SilentlyContinue
  Remove-Item $Zip -Force -ErrorAction SilentlyContinue
  if ($Relaunch) { Start-Process -FilePath $Exe -ArgumentList '--background' }
}
''';
}

final appUpdaterProvider = Provider<AppUpdater>((ref) {
  if (!kReleaseMode || kIsWeb) return NoUpdater();
  final config = ref.watch(configProvider);
  if (Platform.isAndroid) return AndroidUpdater(config);
  if (Platform.isWindows) return WindowsUpdater(config);
  return NoUpdater();
});
