import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../shared/avatar.dart';
import '../../shared/motion.dart';
import '../auth/auth_controller.dart';
import 'mailbox_providers.dart';
import 'models.dart';

/// Gmail-style account switcher: every linked company mailbox, its unread count, and
/// one tap to switch. A bottom sheet on phones, a dialog on larger screens.
Future<void> showAccountSwitcher(BuildContext context, WidgetRef ref) {
  final wide = MediaQuery.sizeOf(context).width >= 600;
  const sheet = _AccountSwitcher();
  return wide
      ? showDialog<void>(
          context: context,
          builder: (_) => const Dialog(
            alignment: Alignment.topRight,
            insetPadding: EdgeInsets.fromLTRB(24, 72, 24, 24),
            child: SizedBox(width: 400, child: sheet),
          ),
        )
      : showModalBottomSheet<void>(
          context: context,
          isScrollControlled: true,
          builder: (_) => const SafeArea(child: sheet),
        );
}

/// Switches the mailbox in use and resets folder/message selection.
void switchAccount(WidgetRef ref, String? accountId) {
  ref.read(selectedAccountIdProvider.notifier).select(accountId);
  ref.read(selectedFolderIdProvider.notifier).select(null);
  ref.read(selectedMessageIdProvider.notifier).select(null);
}

class _AccountSwitcher extends ConsumerWidget {
  const _AccountSwitcher();

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final theme = Theme.of(context);
    final accounts = ref.watch(accountsProvider).value ?? const <MailAccount>[];
    final current = ref.watch(currentAccountProvider);
    final me = ref.watch(sessionKeyProvider) ?? '';
    final others = accounts.where((a) => a.id != current?.id).toList();

    void go(String location) {
      Navigator.pop(context);
      context.push(location);
    }

    return ConstrainedBox(
      constraints: BoxConstraints(
        maxHeight: MediaQuery.sizeOf(context).height * 0.85,
      ),
      child: ListView(
        shrinkWrap: true,
        padding: const EdgeInsets.fromLTRB(16, 8, 16, 16),
        children: [
          Text(
            'Signed in as $me',
            textAlign: TextAlign.center,
            style: theme.textTheme.bodySmall?.copyWith(
              color: theme.colorScheme.onSurfaceVariant,
            ),
          ),
          const SizedBox(height: 12),
          if (current != null)
            Container(
              padding: const EdgeInsets.all(20),
              decoration: BoxDecoration(
                color: theme.colorScheme.surfaceContainerLowest,
                borderRadius: BorderRadius.circular(24),
              ),
              child: Column(
                children: [
                  Hero(
                    tag: 'account-avatar',
                    child: LetterAvatar(
                      name: current.displayName ?? '',
                      email: current.email,
                      radius: 36,
                    ),
                  ),
                  const SizedBox(height: 12),
                  if ((current.displayName ?? '').isNotEmpty)
                    Text(
                      current.displayName!,
                      style: theme.textTheme.titleMedium,
                    ),
                  Text(current.email, style: theme.textTheme.bodyLarge),
                  const SizedBox(height: 4),
                  _StatusLine(account: current),
                  const SizedBox(height: 16),
                  OutlinedButton(
                    onPressed: () => go('/accounts'),
                    child: const Text('Manage your accounts'),
                  ),
                ],
              ),
            ),
          const SizedBox(height: 8),
          for (final (i, a) in others.indexed)
            EnterAnimation(
              index: i,
              child: _AccountRow(
                account: a,
                onTap: () {
                  Navigator.pop(context);
                  switchAccount(ref, a.id);
                  ScaffoldMessenger.of(context).showSnackBar(
                    SnackBar(
                      content: Text('Switched to ${a.email}'),
                      duration: const Duration(seconds: 2),
                    ),
                  );
                },
              ),
            ),
          ListTile(
            leading: const CircleAvatar(
              radius: 20,
              child: Icon(Icons.person_add_alt_1_outlined),
            ),
            title: const Text('Add another account'),
            subtitle: const Text('Any company mailbox you use'),
            onTap: () => go('/accounts?add=1'),
          ),
          const Divider(height: 24),
          ListTile(
            leading: const Icon(Icons.logout),
            title: const Text('Sign out of MbunieMail'),
            onTap: () {
              Navigator.pop(context);
              ref.read(authControllerProvider.notifier).logout();
            },
          ),
        ],
      ),
    );
  }
}

class _AccountRow extends ConsumerWidget {
  const _AccountRow({required this.account, required this.onTap});
  final MailAccount account;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final folders = ref.watch(foldersProvider(account.id)).value;
    final unread =
        folders?.where((f) => f.role == 'inbox').firstOrNull?.unread ?? 0;
    final theme = Theme.of(context);
    return ListTile(
      onTap: onTap,
      leading: LetterAvatar(
        name: account.displayName ?? '',
        email: account.email,
      ),
      title: Text(account.email, maxLines: 1, overflow: TextOverflow.ellipsis),
      subtitle: _StatusLine(account: account),
      trailing: unread > 0
          ? Text(
              unread > 99 ? '99+' : '$unread',
              semanticsLabel: '$unread unread',
              style: theme.textTheme.labelLarge?.copyWith(
                color: theme.colorScheme.primary,
                fontWeight: FontWeight.w700,
              ),
            )
          : null,
    );
  }
}

class _StatusLine extends StatelessWidget {
  const _StatusLine({required this.account});
  final MailAccount account;

  @override
  Widget build(BuildContext context) {
    final scheme = Theme.of(context).colorScheme;
    final (text, bad) = switch (account.status) {
      'auth_failed' => ('Password needs updating', true),
      'provider_unreachable' => ('Mail server unreachable', true),
      'throttled' => ('Server busy — retrying', true),
      'disabled' => ('Disabled by your administrator', true),
      'domain_not_allowed' => ('Domain no longer allowed', true),
      _ => (
        account.provider == 'gmail' ? 'Google Workspace' : 'Company mailbox',
        false,
      ),
    };
    return Text(
      text,
      style: Theme.of(context).textTheme.bodySmall
          ?.copyWith(color: bad ? scheme.error : scheme.onSurfaceVariant),
    );
  }
}
