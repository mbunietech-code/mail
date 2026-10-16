import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import 'app_lock_controller.dart';
import 'set_pin_screen.dart';

final _biometricAvailableProvider = FutureProvider.autoDispose<bool>(
  (ref) => ref.read(biometricAuthProvider).available(),
);

/// Settings → App lock: on/off, change PIN, fingerprint, and when to lock.
class AppLockSettings extends ConsumerWidget {
  const AppLockSettings({super.key});

  static const timeouts = {
    0: 'Immediately',
    60: 'After 1 minute',
    300: 'After 5 minutes',
  };

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final lock = ref.watch(appLockProvider);
    final ctrl = ref.read(appLockProvider.notifier);
    final config = lock.config;
    final biometric = ref.watch(_biometricAvailableProvider).value ?? false;

    Future<String?> askPin(String title) => Navigator.of(context).push<String>(
      MaterialPageRoute(builder: (_) => SetPinScreen(title: title)),
    );

    return Column(
      children: [
        SwitchListTile(
          secondary: const Icon(Icons.lock_outline),
          title: const Text('App lock'),
          subtitle: const Text('Ask for a PIN to open MbunieMail'),
          value: config != null,
          onChanged: !lock.ready
              ? null
              : (on) async {
                  if (!on) return ctrl.disable();
                  final pin = await askPin('Set a PIN');
                  if (pin != null) {
                    await ctrl.enable(pin, biometric: biometric);
                  }
                },
        ),
        if (config != null) ...[
          if (biometric)
            SwitchListTile(
              secondary: const Icon(Icons.fingerprint),
              title: const Text('Unlock with fingerprint'),
              value: config.biometric,
              onChanged: (on) => ctrl.setBiometric(on),
            ),
          ListTile(
            leading: const Icon(Icons.timer_outlined),
            title: const Text('Lock when I leave the app'),
            trailing: DropdownButton<int>(
              value: timeouts.containsKey(config.timeoutSeconds)
                  ? config.timeoutSeconds
                  : 0,
              underline: const SizedBox.shrink(),
              items: [
                for (final e in timeouts.entries)
                  DropdownMenuItem(value: e.key, child: Text(e.value)),
              ],
              onChanged: (v) => ctrl.setTimeout(v ?? 0),
            ),
          ),
          ListTile(
            leading: const Icon(Icons.pin_outlined),
            title: const Text('Change PIN'),
            onTap: () async {
              final pin = await askPin('New PIN');
              if (pin != null) {
                await ctrl.changePin(pin);
                if (context.mounted) {
                  ScaffoldMessenger.of(
                    context,
                  ).showSnackBar(const SnackBar(content: Text('PIN changed')));
                }
              }
            },
          ),
        ],
      ],
    );
  }
}
