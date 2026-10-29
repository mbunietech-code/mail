import 'dart:io';

import 'package:flutter/foundation.dart';
import 'package:tray_manager/tray_manager.dart';
import 'package:window_manager/window_manager.dart';

import '../updates/app_updater.dart';

/// Windows: MbunieMail lives in the notification area like other mail apps.
/// Closing the window hides it to the tray, where the app keeps syncing; the tray
/// icon opens it again, and its menu quits for real. It starts with Windows, hidden
/// in the tray (`--background`), so mail keeps arriving without opening it.
class DesktopShell with TrayListener, WindowListener {
  DesktopShell._(this._updater);

  final AppUpdater _updater;
  static DesktopShell? _instance;

  static const _openKey = 'open';
  static const _startupKey = 'startup';
  static const _quitKey = 'quit';

  static Future<void> init({
    required bool startHidden,
    required AppUpdater updater,
  }) async {
    if (kIsWeb || !Platform.isWindows || _instance != null) return;
    final shell = _instance = DesktopShell._(updater);
    await windowManager.ensureInitialized();
    await windowManager.setTitle('MbunieMail');
    await windowManager.setPreventClose(true);
    windowManager.addListener(shell);

    await shell._enableStartupOnFirstRun();
    // Keep the entry pointing at this copy, in case the app was moved.
    if (await _StartWithWindows.isEnabled()) await _StartWithWindows.enable();

    await trayManager.setIcon('assets/tray_icon.ico');
    await trayManager.setToolTip('MbunieMail');
    trayManager.addListener(shell);
    await shell._buildMenu();

    if (updater is WindowsUpdater) {
      updater.exitApp = shell._exit;
      updater.isWindowVisible = windowManager.isVisible;
      updater.windowHidden(startHidden);
    }
  }

  /// Starting with Windows is on by default; once the user turns it off in the tray
  /// menu it stays off. A marker file records that the default has been applied.
  Future<void> _enableStartupOnFirstRun() async {
    final appData = Platform.environment['APPDATA'];
    if (appData == null) return;
    final marker = File('$appData\\MbunieMail\\startup-default-applied');
    if (await marker.exists()) return;
    await _StartWithWindows.enable();
    await marker.create(recursive: true);
  }

  Future<void> _buildMenu() async {
    final startsWithWindows = await _StartWithWindows.isEnabled();
    await trayManager.setContextMenu(
      Menu(
        items: [
          MenuItem(key: _openKey, label: 'Open MbunieMail'),
          MenuItem.separator(),
          MenuItem.checkbox(
            key: _startupKey,
            label: 'Start with Windows',
            checked: startsWithWindows,
          ),
          MenuItem.separator(),
          MenuItem(key: _quitKey, label: 'Quit MbunieMail'),
        ],
      ),
    );
  }

  Future<void> _show() async {
    await windowManager.show();
    if (await windowManager.isMinimized()) await windowManager.restore();
    await windowManager.focus();
    _hiddenChanged(false);
  }

  Future<void> _hide() async {
    await windowManager.hide();
    _hiddenChanged(true);
  }

  void _hiddenChanged(bool hidden) {
    final u = _updater;
    if (u is WindowsUpdater) u.windowHidden(hidden);
  }

  Future<void> _quit() async {
    // A downloaded update is installed on the way out (no restart).
    final u = _updater;
    if (u is WindowsUpdater && await u.apply(relaunch: false)) return;
    await _exit();
  }

  Future<void> _exit() async {
    await trayManager.destroy();
    await windowManager.setPreventClose(false);
    await windowManager.destroy();
  }

  @override
  void onWindowClose() => _hide();

  // Also covers the window being brought back by opening MbunieMail again.
  @override
  void onWindowFocus() => _hiddenChanged(false);

  @override
  void onTrayIconMouseDown() => _show();

  @override
  void onTrayIconRightMouseDown() => trayManager.popUpContextMenu();

  @override
  Future<void> onTrayMenuItemClick(MenuItem menuItem) async {
    switch (menuItem.key) {
      case _openKey:
        await _show();
      case _startupKey:
        if (await _StartWithWindows.isEnabled()) {
          await _StartWithWindows.disable();
        } else {
          await _StartWithWindows.enable();
        }
        await _buildMenu();
      case _quitKey:
        await _quit();
    }
  }
}

/// The per-user "Run" registry entry that starts MbunieMail, hidden in the tray,
/// when the user signs in to Windows.
class _StartWithWindows {
  static const _key = r'HKCU\Software\Microsoft\Windows\CurrentVersion\Run';
  static const _name = 'MbunieMail';

  static Future<bool> isEnabled() async =>
      (await Process.run('reg', ['query', _key, '/v', _name])).exitCode == 0;

  static Future<void> enable() => Process.run('reg', [
    'add',
    _key,
    '/v',
    _name,
    '/t',
    'REG_SZ',
    '/d',
    '"${Platform.resolvedExecutable}" --background',
    '/f',
  ]);

  static Future<void> disable() =>
      Process.run('reg', ['delete', _key, '/v', _name, '/f']);
}
