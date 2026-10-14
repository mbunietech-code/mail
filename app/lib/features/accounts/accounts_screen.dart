import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../core/errors.dart';
import '../../core/providers.dart';
import '../../shared/widgets.dart';
import '../mailbox/mailbox_providers.dart';
import '../mailbox/models.dart';

/// Linked mailboxes and their health (FR-ACCT-001..004). Server hosts are chosen by the
/// administrator; the employee only enters the mailbox address and its password.
class AccountsScreen extends ConsumerWidget {
  const AccountsScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final accounts = ref.watch(accountsProvider);
    return Scaffold(
      appBar: AppBar(title: const Text('Accounts')),
      floatingActionButton: FloatingActionButton.extended(
        onPressed: () => _link(context, ref),
        icon: const Icon(Icons.add),
        label: const Text('Link mailbox'),
      ),
      body: accounts.when(
        loading: () => const Center(child: CircularProgressIndicator()),
        error: (e, _) => ErrorRetry(
          error: e,
          onRetry: () => ref.invalidate(accountsProvider),
        ),
        data: (list) => list.isEmpty
            ? const EmptyState(
                icon: Icons.alternate_email,
                text: 'Link your company mailbox to start',
              )
            : ListView(
                children: [for (final a in list) _AccountTile(account: a)],
              ),
      ),
    );
  }

  static Future<void> _link(BuildContext context, WidgetRef ref) async {
    final result = await showDialog<({String email, String password})>(
      context: context,
      builder: (_) => const _LinkDialog(),
    );
    if (result == null || !context.mounted) return;
    try {
      await withReauth(
        context,
        ref,
        () => ref
            .read(mailRepositoryProvider)
            .linkAccount(email: result.email, password: result.password),
      );
      ref.invalidate(accountsProvider);
      if (context.mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          const SnackBar(
            content: Text('Mailbox linked. Your messages will appear shortly.'),
          ),
        );
      }
    } catch (e) {
      if (context.mounted) showFailure(context, e);
    }
  }
}

class _AccountTile extends ConsumerWidget {
  const _AccountTile({required this.account});
  final MailAccount account;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final a = account;
    final hint = switch (a.status) {
      'auth_failed' => 'The mail server rejected the stored password. Unlink and link again with the new password.',
      'provider_unreachable' =>
        'The mail server is unreachable. MbunieMail retries automatically.',
      'throttled' =>
        'The provider is limiting requests. MbunieMail will retry later.',
      'disabled' => 'Disabled by your administrator.',
      _ => null,
    };
    return Card(
      margin: const EdgeInsets.fromLTRB(16, 8, 16, 0),
      child: ListTile(
        leading: Icon(
          a.needsAttention ? Icons.error_outline : Icons.check_circle_outline,
          color: a.needsAttention
              ? Theme.of(context).colorScheme.error
              : Colors.green.shade700,
        ),
        title: Text(a.email),
        subtitle: Text(
          [
            a.provider == 'gmail' ? 'Gmail' : 'Company mailbox',
            a.status,
            ?hint,
          ].join(' · '),
        ),
        trailing: PopupMenuButton<String>(
          tooltip: 'Account options',
          onSelected: (v) async {
            try {
              if (v == 'sync') {
                await ref.read(mailRepositoryProvider).syncNow(a.id);
              } else if (v == 'unlink') {
                final ok = await showDialog<bool>(
                  context: context,
                  builder: (c) => AlertDialog(
                    title: const Text('Unlink this mailbox?'),
                    content: const Text(
                      'MbunieMail will forget the password and delete its cached copy of this mailbox. Your mail stays on the mail server.',
                    ),
                    actions: [
                      TextButton(
                        onPressed: () => Navigator.pop(c, false),
                        child: const Text('Cancel'),
                      ),
                      FilledButton(
                        onPressed: () => Navigator.pop(c, true),
                        child: const Text('Unlink'),
                      ),
                    ],
                  ),
                );
                if (ok == true && context.mounted) {
                  await withReauth(
                    context,
                    ref,
                    () => ref.read(mailRepositoryProvider).unlinkAccount(a.id),
                  );
                  ref.invalidate(accountsProvider);
                }
              }
            } on AppFailure catch (e) {
              if (context.mounted) showFailure(context, e);
            }
          },
          itemBuilder: (_) => const [
            PopupMenuItem(value: 'sync', child: Text('Sync now')),
            PopupMenuItem(value: 'unlink', child: Text('Unlink')),
          ],
        ),
      ),
    );
  }
}

class _LinkDialog extends StatefulWidget {
  const _LinkDialog();
  @override
  State<_LinkDialog> createState() => _LinkDialogState();
}

class _LinkDialogState extends State<_LinkDialog> {
  final _form = GlobalKey<FormState>();
  final _email = TextEditingController();
  final _password = TextEditingController();

  @override
  void dispose() {
    _email.dispose();
    _password.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) => AlertDialog(
    title: const Text('Link company mailbox'),
    content: Form(
      key: _form,
      child: Column(
        mainAxisSize: MainAxisSize.min,
        children: [
          TextFormField(
            controller: _email,
            keyboardType: TextInputType.emailAddress,
            decoration: const InputDecoration(labelText: 'Mailbox address'),
            validator: (v) => v != null && v.contains('@')
                ? null
                : 'Enter the mailbox address',
          ),
          const SizedBox(height: 12),
          TextFormField(
            controller: _password,
            obscureText: true,
            decoration: const InputDecoration(
              labelText: 'Mailbox password',
              helperText: 'The password you use for this mailbox in webmail',
              helperMaxLines: 2,
            ),
            validator: (v) =>
                v != null && v.isNotEmpty ? null : 'Enter the mailbox password',
          ),
        ],
      ),
    ),
    actions: [
      TextButton(
        onPressed: () => Navigator.pop(context),
        child: const Text('Cancel'),
      ),
      FilledButton(
        onPressed: () {
          if (_form.currentState!.validate()) {
            Navigator.pop(context, (
              email: _email.text.trim(),
              password: _password.text,
            ));
          }
        },
        child: const Text('Link'),
      ),
    ],
  );
}
