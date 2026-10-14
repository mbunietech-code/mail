import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../core/errors.dart';
import '../core/providers.dart';

/// Shared states (FR-UI-003): every data screen shows loading, empty, offline and error
/// with a retry action.
class ErrorRetry extends StatelessWidget {
  const ErrorRetry({super.key, required this.error, required this.onRetry});
  final Object error;
  final VoidCallback onRetry;

  @override
  Widget build(BuildContext context) {
    final failure = error is AppFailure ? error as AppFailure : null;
    return Center(
      child: Padding(
        padding: const EdgeInsets.all(24),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            Icon(
              failure?.isOffline == true
                  ? Icons.cloud_off
                  : Icons.error_outline,
              size: 40,
              semanticLabel: failure?.isOffline == true ? 'Offline' : 'Error',
            ),
            const SizedBox(height: 12),
            Text(
              failure?.message ?? 'Something went wrong.',
              textAlign: TextAlign.center,
            ),
            const SizedBox(height: 12),
            FilledButton.tonal(onPressed: onRetry, child: const Text('Retry')),
          ],
        ),
      ),
    );
  }
}

class EmptyState extends StatelessWidget {
  const EmptyState({super.key, required this.icon, required this.text});
  final IconData icon;
  final String text;

  @override
  Widget build(BuildContext context) => Center(
    child: Column(
      mainAxisSize: MainAxisSize.min,
      children: [
        Icon(icon, size: 40, color: Theme.of(context).colorScheme.outline),
        const SizedBox(height: 12),
        Text(
          text,
          style: TextStyle(color: Theme.of(context).colorScheme.outline),
        ),
      ],
    ),
  );
}

void showFailure(BuildContext context, Object error) {
  final text = error is AppFailure
      ? error.message
      : 'Something went wrong. Please try again.';
  ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(text)));
}

/// Runs a sensitive action; if the server asks for re-authentication (FR-AUTH-010),
/// prompts for the password, confirms it, and retries once.
Future<T?> withReauth<T>(
  BuildContext context,
  WidgetRef ref,
  Future<T> Function() action,
) async {
  try {
    return await action();
  } on AppFailure catch (e) {
    if (e.code != 'REAUTH_REQUIRED' || !context.mounted) rethrow;
    final password = await showDialog<String>(
      context: context,
      builder: (_) => const _ReauthDialog(),
    );
    if (password == null || password.isEmpty) return null;
    await ref.read(apiClientProvider).postJson('auth/reauth', {
      'password': password,
    });
    return action();
  }
}

class _ReauthDialog extends StatefulWidget {
  const _ReauthDialog();
  @override
  State<_ReauthDialog> createState() => _ReauthDialogState();
}

class _ReauthDialogState extends State<_ReauthDialog> {
  final _ctrl = TextEditingController();

  @override
  void dispose() {
    _ctrl.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) => AlertDialog(
    title: const Text('Confirm it’s you'),
    content: TextField(
      controller: _ctrl,
      obscureText: true,
      autofocus: true,
      autofillHints: const [AutofillHints.password],
      decoration: const InputDecoration(labelText: 'Your MbunieMail password'),
      onSubmitted: (v) => Navigator.pop(context, v),
    ),
    actions: [
      TextButton(
        onPressed: () => Navigator.pop(context),
        child: const Text('Cancel'),
      ),
      FilledButton(
        onPressed: () => Navigator.pop(context, _ctrl.text),
        child: const Text('Confirm'),
      ),
    ],
  );
}
