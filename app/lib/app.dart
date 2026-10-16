import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import 'core/push/push_service.dart';
import 'core/theme.dart';
import 'features/accounts/accounts_screen.dart';
import 'features/auth/auth_controller.dart';
import 'features/auth/login_screen.dart';
import 'features/auth/mfa_screen.dart';
import 'features/compose/compose_screen.dart';
import 'features/lock/lock_screen.dart';
import 'features/mailbox/home_shell.dart';
import 'features/reader/reader_screen.dart';
import 'features/settings/settings_screen.dart';
import 'shared/new_mail_banner.dart';

final themeModeProvider = NotifierProvider<ThemeModeController, ThemeMode>(
  ThemeModeController.new,
);

class ThemeModeController extends Notifier<ThemeMode> {
  @override
  ThemeMode build() => ThemeMode.system;
  void set(ThemeMode mode) => state = mode;
}

/// Bridges Riverpod auth state to go_router's refresh mechanism.
class _AuthListenable extends ChangeNotifier {
  _AuthListenable(Ref ref) {
    ref.listen(authControllerProvider, (_, _) => notifyListeners());
  }
}

final routerProvider = Provider<GoRouter>((ref) {
  final listenable = _AuthListenable(ref);
  return GoRouter(
    initialLocation: '/mail',
    refreshListenable: listenable,
    redirect: (context, state) {
      final auth = ref.read(authControllerProvider);
      final at = state.matchedLocation;
      final target = switch (auth) {
        AuthLoading() => '/splash',
        SignedOut() => '/login',
        MfaPending() => '/mfa',
        ShowRecoveryCodes() => '/mfa',
        SignedIn() => null,
      };
      if (target != null) return at == target ? null : target;
      // Signed in: never linger on auth screens.
      return const {'/splash', '/login', '/mfa'}.contains(at) ? '/mail' : null;
    },
    routes: [
      GoRoute(path: '/splash', builder: (_, _) => const _Splash()),
      GoRoute(path: '/login', builder: (_, _) => const LoginScreen()),
      GoRoute(path: '/mfa', builder: (_, _) => const MfaScreen()),
      GoRoute(path: '/mail', builder: (_, _) => const HomeShell()),
      GoRoute(
        path: '/message/:id',
        builder: (_, s) => ReaderScreen(messageId: s.pathParameters['id']!),
      ),
      GoRoute(
        path: '/compose',
        builder: (_, s) => ComposeScreen(
          draft: s.extra is ComposeDraft
              ? s.extra! as ComposeDraft
              : const ComposeDraft(),
        ),
      ),
      GoRoute(
        path: '/accounts',
        builder: (_, s) =>
            AccountsScreen(openLink: s.uri.queryParameters['add'] == '1'),
      ),
      GoRoute(path: '/settings', builder: (_, _) => const SettingsScreen()),
    ],
  );
});

class MbunieMailApp extends ConsumerStatefulWidget {
  const MbunieMailApp({super.key});

  @override
  ConsumerState<MbunieMailApp> createState() => _MbunieMailAppState();
}

class _MbunieMailAppState extends ConsumerState<MbunieMailApp> {
  StreamSubscription<String>? _openedFromPush;

  @override
  void initState() {
    super.initState();
    final push = ref.read(pushServiceProvider);
    _openedFromPush = push.openedMessages.listen(
      (id) => ref.read(routerProvider).push('/message/$id'),
    );
    if (ref.read(authControllerProvider) is SignedIn) push.register();
  }

  @override
  void dispose() {
    _openedFromPush?.cancel();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    // Register for new-mail push whenever a session starts (FR-NOTIF-001).
    ref.listen(authControllerProvider, (prev, next) {
      if (next is SignedIn && prev is! SignedIn) {
        ref.read(pushServiceProvider).register();
      }
    });
    return MaterialApp.router(
      title: 'MbunieMail',
      debugShowCheckedModeBanner: false,
      theme: AppTheme.light(),
      darkTheme: AppTheme.dark(),
      themeMode: ref.watch(themeModeProvider),
      routerConfig: ref.watch(routerProvider),
      // The lock covers everything, including new-mail banners.
      builder: (context, child) => AppLockGate(
        child: NewMailBannerHost(
          alerts: ref.read(pushServiceProvider).incoming,
          onOpen: (id) => ref.read(routerProvider).push('/message/$id'),
          child: child ?? const SizedBox.shrink(),
        ),
      ),
    );
  }
}

class _Splash extends StatelessWidget {
  const _Splash();

  @override
  Widget build(BuildContext context) => const Scaffold(
    body: Center(child: CircularProgressIndicator(semanticsLabel: 'Loading')),
  );
}
