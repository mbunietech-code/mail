import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../core/errors.dart';
import 'auth_controller.dart';

/// TOTP verification, policy-forced enrolment, and the one-time recovery codes.
class MfaScreen extends ConsumerStatefulWidget {
  const MfaScreen({super.key});
  @override
  ConsumerState<MfaScreen> createState() => _MfaScreenState();
}

class _MfaScreenState extends ConsumerState<MfaScreen> {
  final _code = TextEditingController();
  bool _busy = false;
  String? _error;

  @override
  void dispose() {
    _code.dispose();
    super.dispose();
  }

  Future<void> _run(Future<void> Function() f) async {
    setState(() {
      _busy = true;
      _error = null;
    });
    try {
      await f();
      _code.clear();
    } on AppFailure catch (e) {
      setState(() => _error = e.message);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final auth = ref.watch(authControllerProvider);
    final ctrl = ref.read(authControllerProvider.notifier);

    final Widget body = switch (auth) {
      ShowRecoveryCodes(:final codes) => Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Text(
            'Save your recovery codes',
            style: Theme.of(context).textTheme.headlineSmall,
          ),
          const SizedBox(height: 8),
          const Text(
            'Each code works once if you lose your phone. They are shown only now — write them down and keep them somewhere safe. Do not screenshot or email them.',
          ),
          const SizedBox(height: 16),
          Card(
            child: Padding(
              padding: const EdgeInsets.all(16),
              child: SelectableText(
                codes.join('\n'),
                style: const TextStyle(fontFamily: 'monospace', fontSize: 16),
              ),
            ),
          ),
          const SizedBox(height: 16),
          FilledButton(
            onPressed: ctrl.acknowledgeRecoveryCodes,
            child: const Text('I have saved them'),
          ),
        ],
      ),
      MfaPending(enrolling: true, secret: null) => Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Text(
            'Set up two-step verification',
            style: Theme.of(context).textTheme.headlineSmall,
          ),
          const SizedBox(height: 8),
          const Text(
            'Your organisation requires an authenticator app (for example Microsoft Authenticator, Google Authenticator or Aegis).',
          ),
          const SizedBox(height: 16),
          FilledButton(
            onPressed: _busy ? null : () => _run(ctrl.beginEnrollment),
            child: const Text('Start setup'),
          ),
        ],
      ),
      MfaPending(enrolling: true, :final secret?) => Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Text(
            'Add MbunieMail to your authenticator',
            style: Theme.of(context).textTheme.headlineSmall,
          ),
          const SizedBox(height: 8),
          const Text(
            'In the app choose “Enter a setup key”, type this key, then enter the 6-digit code it shows.',
          ),
          const SizedBox(height: 12),
          Card(
            child: ListTile(
              title: SelectableText(
                secret
                    .replaceAllMapped(RegExp(r'.{4}'), (m) => '${m[0]} ')
                    .trim(),
                style: const TextStyle(fontFamily: 'monospace'),
              ),
              trailing: IconButton(
                tooltip: 'Copy key',
                icon: const Icon(Icons.copy),
                onPressed: () => Clipboard.setData(ClipboardData(text: secret)),
              ),
            ),
          ),
          const SizedBox(height: 12),
          _codeField(() => _run(() => ctrl.confirmEnrollment(_code.text))),
          const SizedBox(height: 12),
          FilledButton(
            onPressed: _busy
                ? null
                : () => _run(() => ctrl.confirmEnrollment(_code.text)),
            child: const Text('Confirm'),
          ),
        ],
      ),
      _ => Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Text(
            'Two-step verification',
            style: Theme.of(context).textTheme.headlineSmall,
          ),
          const SizedBox(height: 8),
          const Text(
            'Enter the 6-digit code from your authenticator app, or one of your recovery codes.',
          ),
          const SizedBox(height: 16),
          _codeField(() => _run(() => ctrl.verifyMfa(_code.text))),
          const SizedBox(height: 12),
          FilledButton(
            onPressed: _busy
                ? null
                : () => _run(() => ctrl.verifyMfa(_code.text)),
            child: const Text('Verify'),
          ),
        ],
      ),
    };

    return Scaffold(
      appBar: AppBar(
        leading: auth is ShowRecoveryCodes
            ? null
            : IconButton(
                tooltip: 'Cancel',
                icon: const Icon(Icons.close),
                onPressed: ctrl.logout,
              ),
      ),
      body: Center(
        child: SingleChildScrollView(
          padding: const EdgeInsets.all(24),
          child: ConstrainedBox(
            constraints: const BoxConstraints(maxWidth: 440),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: [
                body,
                if (_error != null) ...[
                  const SizedBox(height: 12),
                  Text(
                    _error!,
                    style: TextStyle(
                      color: Theme.of(context).colorScheme.error,
                    ),
                  ),
                ],
              ],
            ),
          ),
        ),
      ),
    );
  }

  Widget _codeField(VoidCallback onSubmit) => TextField(
    controller: _code,
    autofocus: true,
    keyboardType: TextInputType.visiblePassword,
    autofillHints: const [AutofillHints.oneTimeCode],
    maxLength: 16,
    decoration: const InputDecoration(labelText: 'Code', counterText: ''),
    onSubmitted: (_) => onSubmit(),
  );
}
