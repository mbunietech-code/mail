import 'package:flutter_secure_storage/flutter_secure_storage.dart';

/// Session tokens. Production: Android Keystore (AES-GCM, RSA-OAEP wrapped key) and
/// Windows DPAPI + Credential Manager via flutter_secure_storage — never plain files or
/// shared preferences (SDD ADR-006, risk R-14).
abstract class TokenStore {
  Future<StoredSession?> read();
  Future<void> write(StoredSession session);
  Future<void> clear();
}

class StoredSession {
  const StoredSession({
    required this.accessToken,
    required this.refreshToken,
    required this.sessionId,
    required this.userEmail,
  });

  final String accessToken;
  final String refreshToken;
  final String sessionId;
  final String userEmail;

  StoredSession copyWith({String? accessToken, String? refreshToken}) =>
      StoredSession(
        accessToken: accessToken ?? this.accessToken,
        refreshToken: refreshToken ?? this.refreshToken,
        sessionId: sessionId,
        userEmail: userEmail,
      );
}

class SecureTokenStore implements TokenStore {
  SecureTokenStore([FlutterSecureStorage? storage])
    : _storage =
          storage ??
          const FlutterSecureStorage(
            aOptions: AndroidOptions(resetOnError: true),
            wOptions: WindowsOptions(useBackwardCompatibility: false),
          );

  final FlutterSecureStorage _storage;
  static const _keys = [
    'mbm.access',
    'mbm.refresh',
    'mbm.session',
    'mbm.email',
  ];

  @override
  Future<StoredSession?> read() async {
    final values = await Future.wait(_keys.map((k) => _storage.read(key: k)));
    if (values.any((v) => v == null || v.isEmpty)) return null;
    return StoredSession(
      accessToken: values[0]!,
      refreshToken: values[1]!,
      sessionId: values[2]!,
      userEmail: values[3]!,
    );
  }

  @override
  Future<void> write(StoredSession s) async {
    await _storage.write(key: 'mbm.access', value: s.accessToken);
    await _storage.write(key: 'mbm.refresh', value: s.refreshToken);
    await _storage.write(key: 'mbm.session', value: s.sessionId);
    await _storage.write(key: 'mbm.email', value: s.userEmail);
  }

  @override
  Future<void> clear() async {
    for (final k in _keys) {
      await _storage.delete(key: k);
    }
  }
}

/// For tests only.
class MemoryTokenStore implements TokenStore {
  StoredSession? value;

  @override
  Future<StoredSession?> read() async => value;

  @override
  Future<void> write(StoredSession session) async => value = session;

  @override
  Future<void> clear() async => value = null;
}
