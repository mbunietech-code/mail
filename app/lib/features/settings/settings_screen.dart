import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:package_info_plus/package_info_plus.dart';

import '../../app.dart';
import '../../core/providers.dart';
import '../../core/updates/app_updater.dart';
import '../../shared/widgets.dart';
import '../auth/auth_controller.dart';
import '../lock/lock_settings.dart';

final sessionsProvider = FutureProvider.autoDispose<List<Map<String, dynamic>>>(
  (ref) async =>
      ((await ref.watch(apiClientProvider).getJson('auth/sessions'))['data']
              as List)
          .map((e) => (e as Map).cast<String, dynamic>())
          .toList(),
);

final preferencesProvider = FutureProvider.autoDispose<Map<String, dynamic>>(
  (ref) => ref.watch(apiClientProvider).getJson('me/preferences'),
);

class SettingsScreen extends ConsumerWidget {
  const SettingsScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final sessions = ref.watch(sessionsProvider);
    final prefs = ref.watch(preferencesProvider).value;
    final theme = ref.watch(themeModeProvider);

    return Scaffold(
      appBar: AppBar(title: const Text('Settings')),
      body: ListView(
        children: [
          const _Header('Appearance'),
          RadioGroup<ThemeMode>(
            groupValue: theme,
            onChanged: (m) =>
                ref.read(themeModeProvider.notifier).set(m ?? ThemeMode.system),
            child: const Column(
              children: [
                RadioListTile(
                  value: ThemeMode.system,
                  title: Text('Use device setting'),
                ),
                RadioListTile(value: ThemeMode.light, title: Text('Light')),
                RadioListTile(value: ThemeMode.dark, title: Text('Dark')),
              ],
            ),
          ),
          const _Header('Security'),
          const AppLockSettings(),
          const _Header('Privacy'),
          SwitchListTile(
            title: const Text('Ask before loading remote images'),
            subtitle: const Text(
              'Remote images can tell senders when you open their email.',
            ),
            value: prefs == null || prefs['remote_images'] != 'never',
            onChanged: prefs == null
                ? null
                : (on) async {
                    await ref.read(apiClientProvider).patchJson(
                      'me/preferences',
                      {'remote_images': on ? 'ask' : 'never'},
                    );
                    ref.invalidate(preferencesProvider);
                  },
          ),
          SwitchListTile(
            title: const Text('Show subjects in notifications'),
            subtitle: const Text('Off: notifications show only the sender.'),
            value: prefs?['show_subject_in_notifications'] == true,
            onChanged: prefs == null
                ? null
                : (on) async {
                    await ref.read(apiClientProvider).patchJson(
                      'me/preferences',
                      {'show_subject_in_notifications': on},
                    );
                    ref.invalidate(preferencesProvider);
                  },
          ),
          const _Header('Signed-in devices'),
          ...sessions.when(
            loading: () => [
              const Padding(
                padding: EdgeInsets.all(16),
                child: LinearProgressIndicator(),
              ),
            ],
            error: (e, _) => [
              ErrorRetry(
                error: e,
                onRetry: () => ref.invalidate(sessionsProvider),
              ),
            ],
            data: (list) => [
              for (final s in list)
                ListTile(
                  leading: Icon(
                    s['platform'] == 'windows'
                        ? Icons.computer
                        : Icons.phone_android,
                  ),
                  title: Text(
                    '${s['device_name'] ?? 'Unknown device'}${s['current'] == true ? ' (this device)' : ''}',
                  ),
                  subtitle: Text('Network ${s['ip_prefix'] ?? 'unknown'}'),
                  trailing: s['current'] == true
                      ? null
                      : TextButton(
                          onPressed: () async {
                            try {
                              await ref
                                  .read(apiClientProvider)
                                  .delete('auth/sessions/${s['id']}');
                              ref.invalidate(sessionsProvider);
                            } catch (e) {
                              if (context.mounted) showFailure(context, e);
                            }
                          },
                          child: const Text('Sign out'),
                        ),
                ),
            ],
          ),
          const _Header('App updates'),
          const _UpdateSettings(),
          const Divider(),
          ListTile(
            leading: const Icon(Icons.logout),
            title: const Text('Sign out of MbunieMail on this device'),
            onTap: () => ref.read(authControllerProvider.notifier).logout(),
          ),
          const SizedBox(height: 24),
        ],
      ),
    );
  }
}

final _versionProvider = FutureProvider.autoDispose<String>((ref) async {
  final i = await PackageInfo.fromPlatform();
  return '${i.version} (${i.buildNumber})';
});

final _silentUpdatesProvider = FutureProvider.autoDispose<bool>(
  (ref) => ref.watch(appUpdaterProvider).canInstallSilently(),
);

/// MbunieMail updates itself. On Android that is silent once "Install unknown
/// apps" is allowed for it; until then every update needs one tap.
class _UpdateSettings extends ConsumerWidget {
  const _UpdateSettings();

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final version = ref.watch(_versionProvider).value;
    final silent = ref.watch(_silentUpdatesProvider).value ?? true;
    return Column(
      children: [
        ListTile(
          leading: const Icon(Icons.system_update_outlined),
          title: const Text('Updates install automatically'),
          subtitle: Text(
            version == null ? 'MbunieMail' : 'MbunieMail $version',
          ),
        ),
        if (!silent)
          ListTile(
            leading: const Icon(Icons.verified_user_outlined),
            title: const Text('Allow updates without asking'),
            subtitle: const Text(
              'Turn on "Allow from this source" for MbunieMail.',
            ),
            onTap: () async {
              await ref.read(appUpdaterProvider).openInstallSettings();
              ref.invalidate(_silentUpdatesProvider);
            },
          ),
      ],
    );
  }
}

class _Header extends StatelessWidget {
  const _Header(this.text);
  final String text;

  @override
  Widget build(BuildContext context) => Padding(
    padding: const EdgeInsets.fromLTRB(16, 20, 16, 4),
    child: Text(
      text,
      style: Theme.of(context).textTheme.titleSmall
          ?.copyWith(color: Theme.of(context).colorScheme.primary),
    ),
  );
}
