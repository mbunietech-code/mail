import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../shared/brand_logo.dart';
import '../auth/auth_controller.dart';
import 'app_lock_controller.dart';
import 'pin_widgets.dart';

/// Covers the app while it is locked, and hides mail in the app switcher.
/// Only active for a signed-in user with app lock turned on.
class AppLockGate extends ConsumerStatefulWidget {
  const AppLockGate({super.key, required this.child});
  final Widget child;

  @override
  ConsumerState<AppLockGate> createState() => _AppLockGateState();
}

class _AppLockGateState extends ConsumerState<AppLockGate>
    with WidgetsBindingObserver {
  bool _obscured = false;

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addObserver(this);
  }

  @override
  void dispose() {
    WidgetsBinding.instance.removeObserver(this);
    super.dispose();
  }

  @override
  void didChangeAppLifecycleState(AppLifecycleState s) {
    final lock = ref.read(appLockProvider.notifier);
    switch (s) {
      case AppLifecycleState.resumed:
        lock.onForeground();
        setState(() => _obscured = false);
      case AppLifecycleState.inactive:
        // The app switcher snapshot is taken now: show the cover, not mail.
        if (ref.read(appLockProvider).enabled) setState(() => _obscured = true);
      case AppLifecycleState.hidden:
      case AppLifecycleState.paused:
        lock.onBackground();
      case AppLifecycleState.detached:
        break;
    }
  }

  @override
  Widget build(BuildContext context) {
    // Signing out removes the lock (the next person sets their own).
    ref.listen(authControllerProvider, (prev, next) {
      if (prev is SignedIn && next is SignedOut) {
        ref.read(appLockProvider.notifier).disable();
      }
    });
    final signedIn = ref.watch(authControllerProvider) is SignedIn;
    final lock = ref.watch(appLockProvider);
    final locked = signedIn && lock.enabled && lock.locked;
    final cover = signedIn && lock.enabled && (_obscured || !lock.ready);

    return Stack(
      children: [
        // Keep the app alive underneath, but unreachable while locked.
        ExcludeSemantics(
          excluding: locked,
          child: IgnorePointer(ignoring: locked, child: widget.child),
        ),
        if (locked)
          // Above the app's Navigator: give the lock screen its own, for its
          // dialog and tooltips.
          Positioned.fill(
            child: Navigator(
              onGenerateRoute: (_) => PageRouteBuilder<void>(
                pageBuilder: (_, _, _) => const LockScreen(),
              ),
            ),
          )
        else if (cover)
          const Positioned.fill(child: _PrivacyCover()),
      ],
    );
  }
}

class _PrivacyCover extends StatelessWidget {
  const _PrivacyCover();

  @override
  Widget build(BuildContext context) => ColoredBox(
    color: Theme.of(context).colorScheme.surface,
    child: const Center(child: MbunieMailLogo(size: 88)),
  );
}

class LockScreen extends ConsumerStatefulWidget {
  const LockScreen({super.key});

  @override
  ConsumerState<LockScreen> createState() => _LockScreenState();
}

class _LockScreenState extends ConsumerState<LockScreen>
    with SingleTickerProviderStateMixin {
  String _pin = '';
  bool _checking = false;
  int _shake = 0;
  String? _message;
  DateTime? _waitUntil;
  Timer? _ticker;
  bool _biometricAvailable = false;

  late final _intro = AnimationController(
    vsync: this,
    duration: const Duration(milliseconds: 600),
  )..forward();

  @override
  void initState() {
    super.initState();
    final until = ref.read(appLockProvider).config?.lockedUntil;
    if (until != null && until.isAfter(DateTime.now())) _startWait(until);
    _offerBiometrics();
  }

  @override
  void dispose() {
    _ticker?.cancel();
    _intro.dispose();
    super.dispose();
  }

  Future<void> _offerBiometrics() async {
    final config = ref.read(appLockProvider).config;
    if (config == null || !config.biometric) return;
    final available = await ref.read(biometricAuthProvider).available();
    if (!mounted) return;
    setState(() => _biometricAvailable = available);
    if (available && _waitUntil == null) {
      // Let the screen settle before the system dialog appears.
      await Future<void>.delayed(const Duration(milliseconds: 350));
      if (mounted) _biometric();
    }
  }

  Future<void> _biometric() async {
    await ref.read(appLockProvider.notifier).unlockWithBiometrics();
  }

  void _startWait(DateTime until) {
    _ticker?.cancel();
    setState(() => _waitUntil = until);
    _ticker = Timer.periodic(const Duration(seconds: 1), (t) {
      if (!mounted) return;
      if (DateTime.now().isAfter(until)) {
        t.cancel();
        setState(() {
          _waitUntil = null;
          _message = null;
        });
      } else {
        setState(() {}); // refresh the countdown
      }
    });
  }

  Future<void> _digit(String d) async {
    final length = ref.read(appLockProvider).config?.pinLength ?? 4;
    if (_checking || _waitUntil != null || _pin.length >= length) return;
    setState(() => _pin += d);
    if (_pin.length == length) await _submit();
  }

  Future<void> _submit() async {
    setState(() => _checking = true);
    final result = await ref.read(appLockProvider.notifier).verify(_pin);
    if (!mounted) return;
    switch (result) {
      case PinAccepted():
        return; // the gate removes this screen
      case PinRejected(:final attemptsLeft):
        setState(() {
          _shake++;
          _message = attemptsLeft == 1
              ? 'Wrong PIN. 1 try left before a short wait.'
              : 'Wrong PIN. $attemptsLeft tries left.';
        });
      case PinWait(:final until):
        setState(() {
          _shake++;
          _message = 'Too many wrong PINs.';
        });
        _startWait(until);
      case PinWiped():
        await ref.read(authControllerProvider.notifier).logout();
        return;
    }
    setState(() {
      _pin = '';
      _checking = false;
    });
  }

  Future<void> _forgot() async {
    final ok = await showDialog<bool>(
      context: context,
      builder: (dialog) => AlertDialog(
        title: const Text('Forgot your PIN?'),
        content: const Text(
          'Sign out and sign in again with your email password. You can then set a new PIN.',
        ),
        actions: [
          TextButton(
            onPressed: () => Navigator.pop(dialog, false),
            child: const Text('Cancel'),
          ),
          FilledButton(
            onPressed: () => Navigator.pop(dialog, true),
            child: const Text('Sign out'),
          ),
        ],
      ),
    );
    if (ok == true) {
      await ref.read(appLockProvider.notifier).disable();
      await ref.read(authControllerProvider.notifier).logout();
    }
  }

  String get _waitText {
    final left = _waitUntil!.difference(DateTime.now());
    final s = left.inSeconds.clamp(0, 3600) + 1;
    return 'Try again in ${s ~/ 60}:${(s % 60).toString().padLeft(2, '0')}';
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final scheme = theme.colorScheme;
    final length = ref.watch(appLockProvider).config?.pinLength ?? 4;
    final waiting = _waitUntil != null;
    final fade = CurvedAnimation(parent: _intro, curve: Curves.easeOut);

    return Material(
      child: DecoratedBox(
        decoration: BoxDecoration(
          gradient: LinearGradient(
            begin: Alignment.topCenter,
            end: Alignment.bottomCenter,
            colors: [
              scheme.primaryContainer.withValues(alpha: 0.55),
              scheme.surface,
            ],
            stops: const [0, 0.6],
          ),
        ),
        child: SafeArea(
          child: Center(
            child: SingleChildScrollView(
              padding: const EdgeInsets.symmetric(vertical: 24),
              child: FadeTransition(
                opacity: fade,
                child: Column(
                  mainAxisSize: MainAxisSize.min,
                  children: [
                    ScaleTransition(
                      scale: Tween(begin: 0.8, end: 1.0).animate(
                        CurvedAnimation(
                          parent: _intro,
                          curve: Curves.easeOutBack,
                        ),
                      ),
                      child: const MbunieMailLogo(size: 80),
                    ),
                    const SizedBox(height: 20),
                    Text(
                      'Enter your PIN',
                      style: theme.textTheme.headlineSmall?.copyWith(
                        fontWeight: FontWeight.w700,
                      ),
                    ),
                    const SizedBox(height: 8),
                    AnimatedSwitcher(
                      duration: const Duration(milliseconds: 200),
                      child: Text(
                        waiting
                            ? '${_message ?? ''} $_waitText'.trim()
                            : _message ?? 'MbunieMail is locked',
                        key: ValueKey(waiting ? 'wait' : _message),
                        textAlign: TextAlign.center,
                        style: theme.textTheme.bodyMedium?.copyWith(
                          color: _message != null
                              ? scheme.error
                              : scheme.onSurfaceVariant,
                        ),
                      ),
                    ),
                    const SizedBox(height: 28),
                    PinDots(
                      length: length,
                      filled: _pin.length,
                      shakeSignal: _shake,
                    ),
                    const SizedBox(height: 32),
                    PinPad(
                      enabled: !_checking && !waiting,
                      onDigit: _digit,
                      onBackspace: () {
                        if (_pin.isNotEmpty) {
                          setState(
                            () => _pin = _pin.substring(0, _pin.length - 1),
                          );
                        }
                      },
                      leading: _biometricAvailable
                          ? IconButton(
                              tooltip: 'Use fingerprint',
                              iconSize: 32,
                              color: scheme.primary,
                              onPressed: waiting ? null : _biometric,
                              icon: const Icon(Icons.fingerprint),
                            )
                          : null,
                    ),
                    const SizedBox(height: 12),
                    TextButton(
                      onPressed: _forgot,
                      child: const Text('Forgot PIN?'),
                    ),
                  ],
                ),
              ),
            ),
          ),
        ),
      ),
    );
  }
}
