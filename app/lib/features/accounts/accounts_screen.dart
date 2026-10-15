import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../core/errors.dart';
import '../../core/providers.dart';
import '../../shared/avatar.dart';
import '../../shared/motion.dart';
import '../../shared/widgets.dart';
import '../mailbox/account_switcher.dart';
import '../mailbox/mailbox_providers.dart';
import '../mailbox/models.dart';

/// Linked mailboxes and their health (FR-ACCT-001..004). Server hosts are chosen by the
/// administrator; the employee only enters the mailbox address and its password. Only
/// company domains can be linked; several mailboxes can be linked and switched.
class AccountsScreen extends ConsumerStatefulWidget {
  const AccountsScreen({super.key, this.openLink = false});
  final bool openLink;

  @override
  ConsumerState<AccountsScreen> createState() => _AccountsScreenState();
}

class _AccountsScreenState extends ConsumerState<AccountsScreen> {
  @override
  void initState() {
    super.initState();
    if (widget.openLink) {
      WidgetsBinding.instance.addPostFrameCallback((_) {
        if (mounted) _link();
      });
    }
  }

  Future<void> _link() async {
    final result = await showDialog<({String email, String password})>(
      context: context,
      builder: (_) => const _LinkDialog(),
    );
    if (result == null || !mounted) return;
    try {
      final account = await withReauth(
        context,
        ref,
        () => ref
            .read(mailRepositoryProvider)
            .linkAccount(email: result.email, password: result.password),
      );
      ref.invalidate(accountsProvider);
      if (account != null) switchAccount(ref, account.id);
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(
            content: Text(
              '${result.email} linked. Messages will appear shortly.',
            ),
          ),
        );
      }
    } catch (e) {
      if (mounted) showFailure(context, e);
    }
  }

  @override
  Widget build(BuildContext context) {
    final accounts = ref.watch(accountsProvider);
    final current = ref.watch(currentAccountProvider);
    return Scaffold(
      appBar: AppBar(title: const Text('Accounts')),
      floatingActionButton: FloatingActionButton.extended(
        onPressed: _link,
        icon: const Icon(Icons.add),
        label: const Text('Link mailbox'),
      ),
      body: accounts.when(
        loading: () => const SkeletonList(rows: 3, label: 'Loading accounts'),
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
                padding: const EdgeInsets.fromLTRB(16, 8, 16, 96),
                children: [
                  Padding(
                    padding: const EdgeInsets.fromLTRB(4, 0, 4, 12),
                    child: Text(
                      'Tap a mailbox to use it. You can link up to 5 company mailboxes.',
                      style: Theme.of(context).textTheme.bodyMedium?.copyWith(
                        color: Theme.of(context).colorScheme.onSurfaceVariant,
                      ),
                    ),
                  ),
                  for (final (i, a) in list.indexed)
                    EnterAnimation(
                      index: i,
                      child: _AccountTile(
                        account: a,
                        inUse: a.id == current?.id,
                      ),
                    ),
                ],
              ),
      ),
    );
  }
}

class _AccountTile extends ConsumerWidget {
  const _AccountTile({required this.account, required this.inUse});
  final MailAccount account;
  final bool inUse;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final a = account;
    final scheme = Theme.of(context).colorScheme;
    final hint = switch (a.status) {
      'auth_failed' => 'The mail server rejected the stored password. Unlink and link again with the new password.',
      'provider_unreachable' =>
        'The mail server is unreachable. MbunieMail retries automatically.',
      'throttled' =>
        'The provider is limiting requests. MbunieMail will retry later.',
      'disabled' => 'Disabled by your administrator.',
      'domain_not_allowed' => 'Your administrator removed this domain. The mailbox can no longer be used.',
      _ => null,
    };
    return Padding(
      padding: const EdgeInsets.only(bottom: 10),
      child: Card(
        clipBehavior: Clip.antiAlias,
        color: inUse ? scheme.secondaryContainer : null,
        child: InkWell(
          onTap: a.usable && !inUse
              ? () {
                  switchAccount(ref, a.id);
                  context.pop();
                }
              : null,
          child: Padding(
            padding: const EdgeInsets.fromLTRB(16, 14, 4, 14),
            child: Row(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                LetterAvatar(
                  name: a.displayName ?? '',
                  email: a.email,
                  selected: inUse,
                ),
                const SizedBox(width: 16),
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text(
                        a.email,
                        style: Theme.of(context).textTheme.titleSmall,
                      ),
                      const SizedBox(height: 4),
                      Wrap(
                        spacing: 6,
                        runSpacing: 4,
                        children: [
                          _Pill(
                            text: a.provider == 'gmail'
                                ? 'Google Workspace'
                                : 'Company mailbox',
                          ),
                          _Pill(
                            text: a.needsAttention
                                ? 'Needs attention'
                                : (inUse ? 'In use' : 'Connected'),
                            bad: a.needsAttention,
                          ),
                        ],
                      ),
                      if (hint != null) ...[
                        const SizedBox(height: 6),
                        Text(
                          hint,
                          style: Theme.of(context).textTheme.bodySmall
                              ?.copyWith(color: scheme.error),
                        ),
                      ],
                    ],
                  ),
                ),
                PopupMenuButton<String>(
                  tooltip: 'Account options',
                  onSelected: (v) => _menu(context, ref, v),
                  itemBuilder: (_) => [
                    if (a.usable)
                      const PopupMenuItem(
                        value: 'sync',
                        child: Text('Sync now'),
                      ),
                    const PopupMenuItem(value: 'unlink', child: Text('Unlink')),
                  ],
                ),
              ],
            ),
          ),
        ),
      ),
    );
  }

  Future<void> _menu(BuildContext context, WidgetRef ref, String v) async {
    final a = account;
    try {
      if (v == 'sync') {
        await ref.read(mailRepositoryProvider).syncNow(a.id);
        if (context.mounted) {
          ScaffoldMessenger.of(context).showSnackBar(
            const SnackBar(content: Text('Checking for new mail…')),
          );
        }
      } else if (v == 'unlink') {
        final ok = await showDialog<bool>(
          context: context,
          builder: (c) => AlertDialog(
            icon: const Icon(Icons.link_off),
            title: const Text('Unlink this mailbox?'),
            content: Text(
              'MbunieMail will forget the password for ${a.email} and delete its cached copy. Your mail stays on the mail server.',
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
          if (inUse) switchAccount(ref, null);
          ref.invalidate(accountsProvider);
        }
      }
    } on AppFailure catch (e) {
      if (context.mounted) showFailure(context, e);
    }
  }
}

class _Pill extends StatelessWidget {
  const _Pill({required this.text, this.bad = false});
  final String text;
  final bool bad;

  @override
  Widget build(BuildContext context) {
    final scheme = Theme.of(context).colorScheme;
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 2),
      decoration: BoxDecoration(
        color: bad ? scheme.errorContainer : scheme.surfaceContainerHigh,
        borderRadius: BorderRadius.circular(8),
      ),
      child: Text(
        text,
        style: Theme.of(context).textTheme.labelSmall?.copyWith(
          color: bad ? scheme.onErrorContainer : scheme.onSurfaceVariant,
        ),
      ),
    );
  }
}

class _LinkDialog extends ConsumerStatefulWidget {
  const _LinkDialog();
  @override
  ConsumerState<_LinkDialog> createState() => _LinkDialogState();
}

class _LinkDialogState extends ConsumerState<_LinkDialog> {
  final _form = GlobalKey<FormState>();
  final _email = TextEditingController();
  final _password = TextEditingController();
  bool _show = false;

  @override
  void dispose() {
    _email.dispose();
    _password.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final domains = ref.watch(companyDomainsProvider).value ?? const <String>[];
    return AlertDialog(
      icon: const Icon(Icons.alternate_email),
      title: const Text('Link company mailbox'),
      content: Form(
        key: _form,
        child: SingleChildScrollView(
          child: Column(
            mainAxisSize: MainAxisSize.min,
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              if (domains.isNotEmpty) ...[
                Text(
                  'Allowed domains',
                  style: Theme.of(context).textTheme.labelMedium,
                ),
                const SizedBox(height: 6),
                Wrap(
                  spacing: 6,
                  runSpacing: 6,
                  children: [
                    for (final d in domains)
                      ActionChip(
                        label: Text('@$d'),
                        onPressed: () {
                          final local = _email.text.split('@').first.trim();
                          _email.text = '$local@$d';
                          _email.selection = TextSelection.collapsed(
                            offset: local.length,
                          );
                        },
                      ),
                  ],
                ),
                const SizedBox(height: 16),
              ],
              TextFormField(
                controller: _email,
                autofocus: true,
                keyboardType: TextInputType.emailAddress,
                decoration: const InputDecoration(
                  labelText: 'Mailbox address',
                  prefixIcon: Icon(Icons.mail_outline),
                ),
                validator: (v) {
                  final e = (v ?? '').trim();
                  if (!e.contains('@')) return 'Enter the mailbox address';
                  if (!emailInDomains(e, domains)) {
                    return 'Only company domains can be linked';
                  }
                  return null;
                },
              ),
              const SizedBox(height: 12),
              TextFormField(
                controller: _password,
                obscureText: !_show,
                decoration: InputDecoration(
                  labelText: 'Mailbox password',
                  prefixIcon: const Icon(Icons.lock_outline),
                  helperText:
                      'The password you use for this mailbox in webmail',
                  helperMaxLines: 2,
                  suffixIcon: IconButton(
                    tooltip: _show ? 'Hide password' : 'Show password',
                    icon: Icon(_show ? Icons.visibility_off : Icons.visibility),
                    onPressed: () => setState(() => _show = !_show),
                  ),
                ),
                validator: (v) => v != null && v.isNotEmpty
                    ? null
                    : 'Enter the mailbox password',
              ),
            ],
          ),
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
                email: _email.text.trim().toLowerCase(),
                password: _password.text,
              ));
            }
          },
          child: const Text('Link'),
        ),
      ],
    );
  }
}
