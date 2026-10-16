import 'dart:convert';
import 'dart:isolate';
import 'dart:math';
import 'dart:typed_data';

import 'package:crypto/crypto.dart';
import 'package:flutter_secure_storage/flutter_secure_storage.dart';

/// App-lock settings, kept in the platform keystore with the session tokens.
/// The PIN itself is never stored: only a salted PBKDF2-HMAC-SHA256 hash.
class LockConfig {
  const LockConfig({
    required this.hash,
    required this.salt,
    required this.iterations,
    required this.pinLength,
    this.biometric = false,
    this.timeoutSeconds = 0,
    this.failed = 0,
    this.lockedUntil,
  });

  final String hash;
  final String salt;
  final int iterations;
  final int pinLength;
  final bool biometric;

  /// How long the app may sit in the background before it locks again.
  final int timeoutSeconds;
  final int failed;
  final DateTime? lockedUntil;

  LockConfig copyWith({
    bool? biometric,
    int? timeoutSeconds,
    int? failed,
    DateTime? lockedUntil,
    bool clearLockedUntil = false,
  }) => LockConfig(
    hash: hash,
    salt: salt,
    iterations: iterations,
    pinLength: pinLength,
    biometric: biometric ?? this.biometric,
    timeoutSeconds: timeoutSeconds ?? this.timeoutSeconds,
    failed: failed ?? this.failed,
    lockedUntil: clearLockedUntil ? null : lockedUntil ?? this.lockedUntil,
  );

  Map<String, Object?> toJson() => {
    'hash': hash,
    'salt': salt,
    'iter': iterations,
    'len': pinLength,
    'bio': biometric,
    'timeout': timeoutSeconds,
    'failed': failed,
    'until': lockedUntil?.millisecondsSinceEpoch,
  };

  static LockConfig fromJson(Map<String, dynamic> j) => LockConfig(
    hash: '${j['hash']}',
    salt: '${j['salt']}',
    iterations: (j['iter'] as num).toInt(),
    pinLength: (j['len'] as num).toInt(),
    biometric: j['bio'] == true,
    timeoutSeconds: (j['timeout'] as num?)?.toInt() ?? 0,
    failed: (j['failed'] as num?)?.toInt() ?? 0,
    lockedUntil: j['until'] == null
        ? null
        : DateTime.fromMillisecondsSinceEpoch((j['until'] as num).toInt()),
  );
}

abstract class LockStore {
  Future<LockConfig?> read();
  Future<void> write(LockConfig config);
  Future<void> clear();
}

class SecureLockStore implements LockStore {
  SecureLockStore([FlutterSecureStorage? storage])
    : _storage =
          storage ??
          const FlutterSecureStorage(
            aOptions: AndroidOptions(resetOnError: true),
            wOptions: WindowsOptions(useBackwardCompatibility: false),
          );

  static const _key = 'app_lock';
  final FlutterSecureStorage _storage;

  @override
  Future<LockConfig?> read() async {
    final raw = await _storage.read(key: _key);
    if (raw == null) return null;
    try {
      return LockConfig.fromJson(
        (jsonDecode(raw) as Map).cast<String, dynamic>(),
      );
    } catch (_) {
      return null; // unreadable settings: treat as no lock rather than lock out
    }
  }

  @override
  Future<void> write(LockConfig config) =>
      _storage.write(key: _key, value: jsonEncode(config.toJson()));

  @override
  Future<void> clear() => _storage.delete(key: _key);
}

class MemoryLockStore implements LockStore {
  LockConfig? value;

  @override
  Future<LockConfig?> read() async => value;

  @override
  Future<void> write(LockConfig config) async => value = config;

  @override
  Future<void> clear() async => value = null;
}

/// PBKDF2-HMAC-SHA256 for PINs, run off the UI thread.
class PinHasher {
  // 10k rounds keeps unlock under a second on low-end phones (100k took ~5 s on
  // an Infinix). The hash also sits in keystore-encrypted storage, and wrong PINs
  // are rate-limited, which is what protects a short PIN.
  const PinHasher({this.iterations = 10000});

  final int iterations;

  static String newSalt() {
    final r = Random.secure();
    return base64Encode(List<int>.generate(16, (_) => r.nextInt(256)));
  }

  Future<String> hash(String pin, String salt, int iterations) =>
      Isolate.run(() => base64Encode(_pbkdf2(pin, salt, iterations)));

  Future<bool> verify(String pin, LockConfig config) async {
    final actual = base64Decode(
      await hash(pin, config.salt, config.iterations),
    );
    final expected = base64Decode(config.hash);
    if (actual.length != expected.length) return false;
    var diff = 0; // constant time
    for (var i = 0; i < actual.length; i++) {
      diff |= actual[i] ^ expected[i];
    }
    return diff == 0;
  }

  static Uint8List _pbkdf2(String pin, String salt, int iterations) {
    final hmac = Hmac(sha256, utf8.encode(pin));
    var u = hmac.convert([...base64Decode(salt), 0, 0, 0, 1]).bytes;
    final out = Uint8List.fromList(u);
    for (var i = 1; i < iterations; i++) {
      u = hmac.convert(u).bytes;
      for (var j = 0; j < out.length; j++) {
        out[j] ^= u[j];
      }
    }
    return out;
  }
}
