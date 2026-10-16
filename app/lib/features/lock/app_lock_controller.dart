import 'dart:math';

import 'package:flutter/services.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:local_auth/local_auth.dart';
import 'package:local_auth_android/local_auth_android.dart';

import 'lock_store.dart';

/// Fingerprint/face unlock, behind an interface so tests can stand in for it.
abstract class BiometricAuth {
  Future<bool> available();
  Future<bool> authenticate(String reason);
}

class DeviceBiometricAuth implements BiometricAuth {
  final _auth = LocalAuthentication();

  @override
  Future<bool> available() async {
    try {
      return await _auth.canCheckBiometrics &&
          (await _auth.getAvailableBiometrics()).isNotEmpty;
    } on PlatformException {
      return false;
    }
  }

  @override
  Future<bool> authenticate(String reason) async {
    try {
      return await _auth.authenticate(
        localizedReason: reason,
        authMessages: const [
          AndroidAuthMessages(
            signInTitle: 'Unlock MbunieMail',
            signInHint: 'Touch the fingerprint sensor',
            cancelButton: 'Use PIN',
          ),
        ],
        biometricOnly: true, // the PIN pad is the fallback, not the device PIN
        persistAcrossBackgrounding: true,
      );
    } on LocalAuthException {
      return false;
    } on PlatformException {
      return false;
    }
  }
}

final lockStoreProvider = Provider<LockStore>((ref) => SecureLockStore());
final pinHasherProvider = Provider<PinHasher>((ref) => const PinHasher());
final biometricAuthProvider = Provider<BiometricAuth>(
  (ref) => DeviceBiometricAuth(),
);

class AppLockState {
  const AppLockState({this.ready = false, this.config, this.locked = false});

  final bool ready;
  final LockConfig? config;
  final bool locked;

  bool get enabled => config != null;
}

sealed class PinResult {
  const PinResult();
}

class PinAccepted extends PinResult {
  const PinAccepted();
}

class PinRejected extends PinResult {
  const PinRejected(this.attemptsLeft);

  /// Before the next wait (or, near the end, before sign-out).
  final int attemptsLeft;
}

class PinWait extends PinResult {
  const PinWait(this.until);
  final DateTime until;
}

/// Too many wrong PINs: the caller signs the user out (the lock is already cleared).
class PinWiped extends PinResult {
  const PinWiped();
}

/// App lock (PIN, optionally fingerprint). Locks at start and after the app has been
/// in the background longer than the chosen timeout. 5 wrong PINs start a growing
/// wait; 10 sign the user out.
class AppLockController extends Notifier<AppLockState> {
  static const waitAfter = 5;
  static const wipeAfter = 10;

  DateTime? _backgroundSince;
  bool _authenticating = false;

  LockStore get _store => ref.read(lockStoreProvider);
  PinHasher get _hasher => ref.read(pinHasherProvider);

  @override
  AppLockState build() {
    _load();
    return const AppLockState();
  }

  Future<void> _load() async {
    final config = await _store.read();
    // A configured lock always starts locked.
    state = AppLockState(ready: true, config: config, locked: config != null);
  }

  Future<void> enable(
    String pin, {
    bool biometric = false,
    int timeoutSeconds = 0,
  }) async {
    final salt = PinHasher.newSalt();
    final config = LockConfig(
      hash: await _hasher.hash(pin, salt, _hasher.iterations),
      salt: salt,
      iterations: _hasher.iterations,
      pinLength: pin.length,
      biometric: biometric,
      timeoutSeconds: timeoutSeconds,
    );
    await _store.write(config);
    state = AppLockState(ready: true, config: config);
  }

  Future<void> changePin(String pin) async {
    final old = state.config;
    if (old == null) return;
    await enable(
      pin,
      biometric: old.biometric,
      timeoutSeconds: old.timeoutSeconds,
    );
  }

  Future<void> disable() async {
    await _store.clear();
    state = const AppLockState(ready: true);
  }

  Future<void> setBiometric(bool on) =>
      _update(state.config?.copyWith(biometric: on));

  Future<void> setTimeout(int seconds) =>
      _update(state.config?.copyWith(timeoutSeconds: seconds));

  Future<void> _update(LockConfig? config) async {
    if (config == null) return;
    await _store.write(config);
    state = AppLockState(ready: true, config: config, locked: state.locked);
  }

  Future<PinResult> verify(String pin) async {
    final config = state.config;
    if (config == null) return const PinAccepted();
    final until = config.lockedUntil;
    if (until != null && until.isAfter(DateTime.now())) return PinWait(until);

    if (await _hasher.verify(pin, config)) {
      await _update(config.copyWith(failed: 0, clearLockedUntil: true));
      state = AppLockState(ready: true, config: state.config);
      return const PinAccepted();
    }

    final failed = config.failed + 1;
    if (failed >= wipeAfter) {
      await disable();
      return const PinWiped();
    }
    if (failed >= waitAfter) {
      // 30 s, 1 min, 2 min, ... up to 15 min.
      final seconds = min(30 * (1 << (failed - waitAfter)), 15 * 60);
      final wait = DateTime.now().add(Duration(seconds: seconds));
      await _update(config.copyWith(failed: failed, lockedUntil: wait));
      return PinWait(wait);
    }
    await _update(config.copyWith(failed: failed));
    return PinRejected(waitAfter - failed);
  }

  Future<bool> unlockWithBiometrics() async {
    final config = state.config;
    if (config == null || !config.biometric || _authenticating) return false;
    _authenticating = true;
    try {
      final ok = await ref
          .read(biometricAuthProvider)
          .authenticate('Unlock MbunieMail');
      if (ok) {
        await _update(config.copyWith(failed: 0, clearLockedUntil: true));
        state = AppLockState(ready: true, config: state.config);
      }
      return ok;
    } finally {
      _authenticating = false;
    }
  }

  void lockNow() {
    if (state.enabled) {
      state = AppLockState(ready: true, config: state.config, locked: true);
    }
  }

  void onBackground() {
    // The fingerprint dialog itself can pause the app; that is not "leaving".
    if (_authenticating) return;
    _backgroundSince ??= DateTime.now();
  }

  void onForeground() {
    final since = _backgroundSince;
    _backgroundSince = null;
    final config = state.config;
    if (since == null || config == null || state.locked) return;
    if (DateTime.now().difference(since).inSeconds >= config.timeoutSeconds) {
      lockNow();
    }
  }
}

final appLockProvider = NotifierProvider<AppLockController, AppLockState>(
  AppLockController.new,
);
