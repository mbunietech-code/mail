import 'dart:io' show Platform;

import 'package:flutter/foundation.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_secure_storage/flutter_secure_storage.dart';

import '../../core/errors.dart';
import '../../core/ids.dart';
import '../../core/providers.dart';
import '../../core/push/push_service.dart';
import '../../core/storage/token_store.dart';

sealed class AuthState {
  const AuthState();
}

class AuthLoading extends AuthState {
  const AuthLoading();
}

class SignedOut extends AuthState {
  const SignedOut([this.notice]);
  final String? notice;
}

/// Password accepted; a TOTP code (or, for policy-forced enrolment, setup) is needed.
class MfaPending extends AuthState {
  const MfaPending({
    required this.mfaToken,
    required this.enrolling,
    this.secret,
    this.otpauthUri,
  });
  final String mfaToken;
  final bool enrolling;
  final String? secret;
  final String? otpauthUri;
}

/// Enrolment finished: recovery codes are shown once, then the user continues.
class ShowRecoveryCodes extends AuthState {
  const ShowRecoveryCodes(this.codes);
  final List<String> codes;
}

class SignedIn extends AuthState {
  const SignedIn(this.email);
  final String email;
}

final authControllerProvider = NotifierProvider<AuthController, AuthState>(
  AuthController.new,
);

class AuthController extends Notifier<AuthState> {
  @override
  AuthState build() {
    final api = ref.read(apiClientProvider);
    api.onSessionLost = () => state = const SignedOut(
      'You were signed out for security. Please sign in again.',
    );
    Future.microtask(_restore);
    return const AuthLoading();
  }

  Future<void> _restore() async {
    final api = ref.read(apiClientProvider);
    final session = await api.currentSession();
    if (session == null) {
      state = const SignedOut();
      return;
    }
    try {
      await api.getJson(
        'me',
      ); // proves the session is still valid (may refresh)
      state = SignedIn(session.userEmail);
    } on AppFailure catch (e) {
      if (e.isAuthLost) {
        await api.setSession(null);
        state = SignedOut(e.message);
      } else {
        state = SignedIn(
          session.userEmail,
        ); // offline: keep the session, show offline UI
      }
    }
  }

  Future<void> login(String email, String password) async {
    final api = ref.read(apiClientProvider);
    final j = await api.postPublic('auth/login', {
      'email': email.trim(),
      'password': password,
      'device': await _device(),
    });
    await _handleLoginResponse(j);
  }

  Future<void> verifyMfa(String code) async {
    final pending = state;
    if (pending is! MfaPending) return;
    final api = ref.read(apiClientProvider);
    final input = code.replaceAll(' ', '');
    final j = await api.postPublic(
      'auth/mfa/verify',
      RegExp(r'^\d{6}$').hasMatch(input)
          ? {'code': input}
          : {'recovery_code': input},
      bearer: pending.mfaToken,
    );
    await _handleLoginResponse(j);
  }

  Future<void> beginEnrollment() async {
    final pending = state;
    if (pending is! MfaPending || !pending.enrolling) return;
    final j = await ref
        .read(apiClientProvider)
        .postPublic('auth/mfa/enroll', const {}, bearer: pending.mfaToken);
    state = MfaPending(
      mfaToken: pending.mfaToken,
      enrolling: true,
      secret: j['secret'] as String?,
      otpauthUri: j['otpauth_uri'] as String?,
    );
  }

  Future<void> confirmEnrollment(String code) async {
    final pending = state;
    if (pending is! MfaPending) return;
    final api = ref.read(apiClientProvider);
    final j = await api.postPublic('auth/mfa/confirm', {
      'code': code.trim(),
    }, bearer: pending.mfaToken);
    final tokens = (j['tokens'] as Map?)?.cast<String, dynamic>();
    if (tokens != null) {
      await _storeTokens(tokens);
    }
    state = ShowRecoveryCodes(
      (j['recovery_codes'] as List).map((e) => '$e').toList(),
    );
  }

  Future<void> acknowledgeRecoveryCodes() async {
    final s = await ref.read(apiClientProvider).currentSession();
    state = s == null ? const SignedOut() : SignedIn(s.userEmail);
  }

  Future<void> logout() async {
    final api = ref.read(apiClientProvider);
    try {
      await ref.read(pushServiceProvider).unregister();
    } catch (_) {
      // No push on this device, or Firebase unreachable: sign-out continues.
    }
    try {
      await api.postJson('auth/logout');
    } on AppFailure {
      // Signing out locally must always work, even offline.
    }
    await api.setSession(null);
    state = const SignedOut();
  }

  Future<void> _handleLoginResponse(Map<String, dynamic> j) async {
    switch (j['status']) {
      case 'authenticated':
        await _storeTokens(j);
        state = SignedIn('${(j['user'] as Map?)?['email'] ?? ''}');
      case 'mfa_required':
        state = MfaPending(mfaToken: '${j['mfa_token']}', enrolling: false);
      case 'mfa_enrollment_required':
        state = MfaPending(mfaToken: '${j['mfa_token']}', enrolling: true);
      default:
        throw const AppFailure('UNEXPECTED_RESPONSE');
    }
  }

  Future<void> _storeTokens(Map<String, dynamic> j) async {
    await ref
        .read(apiClientProvider)
        .setSession(
          StoredSession(
            accessToken: '${j['access_token']}',
            refreshToken: '${j['refresh_token']}',
            sessionId: '${j['session_id']}',
            userEmail: '${(j['user'] as Map?)?['email'] ?? ''}',
          ),
        );
  }

  /// A stable per-install id lets users recognise and revoke this device (FR-AUTH-012).
  Future<Map<String, String>> _device() async {
    final store = ref.read(deviceIdStoreProvider);
    final id = await store.read() ?? await store.create();
    final platform = kIsWeb
        ? 'other'
        : (Platform.isAndroid
              ? 'android'
              : (Platform.isWindows ? 'windows' : 'other'));
    return {
      'id': id,
      'platform': platform,
      'name': platform == 'android'
          ? 'Android device'
          : (platform == 'windows' ? 'Windows PC' : 'Device'),
    };
  }
}

/// Device identifier storage (not a secret, but kept with the session data).
abstract class DeviceIdStore {
  Future<String?> read();
  Future<String> create();
}

class MemoryDeviceIdStore implements DeviceIdStore {
  String? id;
  @override
  Future<String?> read() async => id;
  @override
  Future<String> create() async => id = newClientId();
}

class SecureDeviceIdStore implements DeviceIdStore {
  static const _storage = FlutterSecureStorage(
    aOptions: AndroidOptions(resetOnError: true),
  );

  @override
  Future<String?> read() => _storage.read(key: 'mbm.device');

  @override
  Future<String> create() async {
    final id = newClientId();
    await _storage.write(key: 'mbm.device', value: id);
    return id;
  }
}

final deviceIdStoreProvider = Provider<DeviceIdStore>(
  (ref) => SecureDeviceIdStore(),
);
