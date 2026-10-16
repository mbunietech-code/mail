import 'dart:convert';
import 'dart:io';

import 'package:crypto/crypto.dart' show sha256;
import 'package:cryptography/cryptography.dart';
import 'package:flutter/foundation.dart';
import 'package:flutter_secure_storage/flutter_secure_storage.dart';
import 'package:path_provider/path_provider.dart';

/// Offline copy of mail the user has already loaded (folders, list pages, opened
/// messages), so the app still shows it without a connection. Nothing here is
/// authoritative: the server is always tried first.
abstract class MailCache {
  Future<Map<String, dynamic>?> read(String key);
  Future<void> write(String key, Map<String, dynamic> value);

  /// Removes everything (sign-out).
  Future<void> clear();
}

/// AES-256-GCM encrypted files in the app's private storage. The key lives in the
/// platform keystore with the session tokens, so a copied cache folder is useless
/// on its own (SDD ADR-006, risk R-14). Old and excess entries are pruned.
class EncryptedFileMailCache implements MailCache {
  EncryptedFileMailCache({
    FlutterSecureStorage? storage,
    Future<Directory> Function()? baseDir,
    this.maxEntries = 300,
    this.maxAge = const Duration(days: 30),
  }) : _storage =
           storage ??
           const FlutterSecureStorage(
             aOptions: AndroidOptions(resetOnError: true),
             wOptions: WindowsOptions(useBackwardCompatibility: false),
           ),
       _baseDir = baseDir ?? getApplicationSupportDirectory;

  static const _keyName = 'mail_cache_key';
  final FlutterSecureStorage _storage;
  final Future<Directory> Function() _baseDir;
  final int maxEntries;
  final Duration maxAge;
  final _aes = AesGcm.with256bits();
  SecretKey? _key;
  Directory? _dir;
  bool _pruned = false;

  Future<SecretKey> _secretKey() async {
    if (_key != null) return _key!;
    final stored = await _storage.read(key: _keyName);
    if (stored != null) return _key = SecretKey(base64Decode(stored));
    final key = await _aes.newSecretKey();
    await _storage.write(
      key: _keyName,
      value: base64Encode(await key.extractBytes()),
    );
    return _key = key;
  }

  Future<Directory> _folder() async {
    if (_dir != null) return _dir!;
    final dir = Directory('${(await _baseDir()).path}/mail_cache');
    await dir.create(recursive: true);
    _dir = dir;
    if (!_pruned) {
      _pruned = true;
      _prune(dir); // in the background; reads never wait for it
    }
    return dir;
  }

  // File names are hashes, so ids and folder names do not leak into the file system.
  Future<File> _file(String key) async =>
      File('${(await _folder()).path}/${sha256.convert(utf8.encode(key))}.bin');

  @override
  Future<Map<String, dynamic>?> read(String key) async {
    try {
      final file = await _file(key);
      if (!await file.exists()) return null;
      final box = SecretBox.fromConcatenation(
        await file.readAsBytes(),
        nonceLength: _aes.nonceLength,
        macLength: _aes.macAlgorithm.macLength,
      );
      final clear = await _aes.decrypt(
        box,
        secretKey: await _secretKey(),
        aad: utf8.encode(key), // a file cannot be swapped in for another key
      );
      return (jsonDecode(utf8.decode(clear)) as Map).cast<String, dynamic>();
    } catch (e) {
      debugPrint('Mail cache read skipped: $e');
      return null;
    }
  }

  @override
  Future<void> write(String key, Map<String, dynamic> value) async {
    try {
      final box = await _aes.encrypt(
        utf8.encode(jsonEncode(value)),
        secretKey: await _secretKey(),
        aad: utf8.encode(key),
      );
      final file = await _file(key);
      final tmp = File('${file.path}.tmp');
      await tmp.writeAsBytes(box.concatenation(), flush: true);
      await tmp.rename(file.path);
    } catch (e) {
      debugPrint('Mail cache write skipped: $e');
    }
  }

  @override
  Future<void> clear() async {
    try {
      final dir = Directory('${(await _baseDir()).path}/mail_cache');
      if (await dir.exists()) await dir.delete(recursive: true);
      await _storage.delete(key: _keyName);
    } catch (e) {
      debugPrint('Mail cache clear incomplete: $e');
    }
    _key = null;
    _dir = null;
    _pruned = false;
  }

  Future<void> _prune(Directory dir) async {
    try {
      final files = <(File, DateTime)>[];
      await for (final e in dir.list()) {
        if (e is File) files.add((e, (await e.stat()).modified));
      }
      files.sort((a, b) => b.$2.compareTo(a.$2)); // newest first
      final cutoff = DateTime.now().subtract(maxAge);
      for (var i = 0; i < files.length; i++) {
        if (i >= maxEntries || files[i].$2.isBefore(cutoff)) {
          await files[i].$1.delete();
        }
      }
    } catch (e) {
      debugPrint('Mail cache prune skipped: $e');
    }
  }
}

class MemoryMailCache implements MailCache {
  final Map<String, Map<String, dynamic>> values = {};

  @override
  Future<Map<String, dynamic>?> read(String key) async => values[key];

  @override
  Future<void> write(String key, Map<String, dynamic> value) async =>
      values[key] = value;

  @override
  Future<void> clear() async => values.clear();
}
