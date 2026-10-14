import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:intl/intl.dart';

import '../../core/providers.dart';
import '../../shared/widgets.dart';
import '../compose/compose_screen.dart';
import '../reader/reader_screen.dart';
import 'mailbox_providers.dart';
import 'models.dart';

/// Adaptive mailbox (FR-UI-001): one pane below 600 dp (drawer + list), two panes up to
/// 1100 dp (sidebar + list), three panes above (sidebar + list + reader).
class HomeShell extends ConsumerWidget {
  const HomeShell({super.key});

  static const compact = 600.0;
  static const expanded = 1100.0;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final accounts = ref.watch(accountsProvider);
    return LayoutBuilder(
      builder: (context, c) {
        final width = c.maxWidth;
        final threePane = width >= expanded;

        void openMessage(MessageSummary m) {
          ref.read(selectedMessageIdProvider.notifier).select(m.id);
          if (!threePane) context.push('/message/${m.id}');
        }

        final body = accounts.when(
          loading: () => const Center(
            child: CircularProgressIndicator(
              semanticsLabel: 'Loading accounts',
            ),
          ),
          error: (e, _) => ErrorRetry(
            error: e,
            onRetry: () => ref.invalidate(accountsProvider),
          ),
          data: (list) => list.isEmpty
              ? const _NoAccounts()
              : _MailArea(threePane: threePane, onOpen: openMessage),
        );

        return CallbackShortcuts(
          bindings: {
            const SingleActivator(LogicalKeyboardKey.keyC): () =>
                context.push('/compose', extra: const ComposeDraft()),
            const SingleActivator(LogicalKeyboardKey.f5): () => _refresh(ref),
            const SingleActivator(LogicalKeyboardKey.keyR, control: true): () =>
                _refresh(ref),
          },
          child: Focus(
            autofocus: true,
            child: Scaffold(
              appBar: AppBar(
                title: Text(
                  ref.watch(currentFolderProvider)?.label ?? 'MbunieMail',
                ),
                actions: [
                  IconButton(
                    tooltip: 'Search',
                    icon: const Icon(Icons.search),
                    onPressed: () => _openSearch(context, ref),
                  ),
                  IconButton(
                    tooltip: 'Refresh (F5)',
                    icon: const Icon(Icons.refresh),
                    onPressed: () => _refresh(ref),
                  ),
                  IconButton(
                    tooltip: 'Settings',
                    icon: const Icon(Icons.settings_outlined),
                    onPressed: () => context.push('/settings'),
                  ),
                ],
              ),
              drawer: width < compact
                  ? const Drawer(child: SafeArea(child: _Sidebar()))
                  : null,
              floatingActionButton: FloatingActionButton.extended(
                onPressed: () =>
                    context.push('/compose', extra: const ComposeDraft()),
                icon: const Icon(Icons.edit_outlined),
                label: const Text('Compose'),
                tooltip: 'Compose (C)',
              ),
              body: Column(
                children: [
                  const _StatusBanner(),
                  Expanded(
                    child: width < compact
                        ? body
                        : Row(
                            children: [
                              const SizedBox(width: 260, child: _Sidebar()),
                              const VerticalDivider(width: 1),
                              Expanded(child: body),
                            ],
                          ),
                  ),
                ],
              ),
            ),
          ),
        );
      },
    );
  }

  static Future<void> _refresh(WidgetRef ref) async {
    final account = ref.read(currentAccountProvider);
    final folder = ref.read(currentFolderProvider);
    if (account != null) {
      try {
        await ref.read(mailRepositoryProvider).syncNow(account.id);
      } catch (_) {
        // Rate limits or provider trouble: the list still refreshes from cache.
      }
    }
    if (folder != null) {
      await ref.read(messageListProvider(folder.id).notifier).refresh();
    }
    ref.read(syncStatusProvider.notifier).poll();
  }

  static Future<void> _openSearch(BuildContext context, WidgetRef ref) async {
    final account = ref.read(currentAccountProvider);
    if (account == null) return;
    await showSearch<void>(
      context: context,
      delegate: _MailSearch(ref, account.id),
    );
  }
}

class _MailArea extends ConsumerWidget {
  const _MailArea({required this.threePane, required this.onOpen});
  final bool threePane;
  final void Function(MessageSummary) onOpen;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final folder = ref.watch(currentFolderProvider);
    if (folder == null) {
      return const Center(
        child: CircularProgressIndicator(semanticsLabel: 'Loading folders'),
      );
    }
    final list = MessageList(folderId: folder.id, onOpen: onOpen);
    if (!threePane) return list;
    final selected = ref.watch(selectedMessageIdProvider);
    return Row(
      children: [
        SizedBox(width: 400, child: list),
        const VerticalDivider(width: 1),
        Expanded(
          child: selected == null
              ? const EmptyState(
                  icon: Icons.mark_email_read_outlined,
                  text: 'Select a message to read',
                )
              : ReaderView(key: ValueKey(selected), messageId: selected),
        ),
      ],
    );
  }
}

class MessageList extends ConsumerWidget {
  const MessageList({super.key, required this.folderId, required this.onOpen});
  final String folderId;
  final void Function(MessageSummary) onOpen;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final s = ref.watch(messageListProvider(folderId));
    final ctrl = ref.read(messageListProvider(folderId).notifier);

    if (s.items.isEmpty && s.loading) {
      return const Center(
        child: CircularProgressIndicator(semanticsLabel: 'Loading messages'),
      );
    }
    if (s.items.isEmpty && s.error != null) {
      return ErrorRetry(error: s.error!, onRetry: ctrl.refresh);
    }

    return RefreshIndicator(
      onRefresh: () => HomeShell._refresh(ref),
      child: s.items.isEmpty
          ? ListView(
              children: const [
                SizedBox(height: 120),
                EmptyState(
                  icon: Icons.inbox_outlined,
                  text: 'No messages here',
                ),
              ],
            )
          : NotificationListener<ScrollNotification>(
              onNotification: (n) {
                if (n.metrics.pixels > n.metrics.maxScrollExtent - 400) {
                  ctrl.loadMore();
                }
                return false;
              },
              child: ListView.separated(
                itemCount: s.items.length + (s.exhausted ? 0 : 1),
                separatorBuilder: (_, _) => const Divider(height: 1),
                itemBuilder: (context, i) {
                  if (i >= s.items.length) {
                    return Padding(
                      padding: const EdgeInsets.all(16),
                      child: Center(
                        child: s.error != null
                            ? TextButton(
                                onPressed: ctrl.loadMore,
                                child: const Text('Load more'),
                              )
                            : const CircularProgressIndicator(
                                semanticsLabel: 'Loading more',
                              ),
                      ),
                    );
                  }
                  final m = s.items[i];
                  return MessageTile(
                    message: m,
                    selected: ref.watch(selectedMessageIdProvider) == m.id,
                    onTap: () {
                      if (!m.isSeen) {
                        ctrl.setFlags(m, seen: true).catchError((_) {});
                      }
                      onOpen(m);
                    },
                    onStar: () => ctrl
                        .setFlags(m, flagged: !m.isFlagged)
                        .catchError((Object e) {
                          if (context.mounted) showFailure(context, e);
                        }),
                  );
                },
              ),
            ),
    );
  }
}

class MessageTile extends StatelessWidget {
  const MessageTile({
    super.key,
    required this.message,
    required this.onTap,
    required this.onStar,
    this.selected = false,
  });
  final MessageSummary message;
  final VoidCallback onTap;
  final VoidCallback onStar;
  final bool selected;

  @override
  Widget build(BuildContext context) {
    final m = message;
    final unread = !m.isSeen;
    final weight = unread ? FontWeight.w700 : FontWeight.w400;
    final now = DateTime.now();
    final when =
        m.date.year == now.year &&
            m.date.month == now.month &&
            m.date.day == now.day
        ? DateFormat.Hm().format(m.date)
        : DateFormat.MMMd().format(m.date);
    return Semantics(
      label:
          '${unread ? 'Unread. ' : ''}From ${m.from.display}. ${m.subject}. $when${m.hasAttachments ? '. Has attachments' : ''}',
      excludeSemantics: true,
      button: true,
      child: ListTile(
        selected: selected,
        onTap: onTap,
        leading: CircleAvatar(
          child: Text(
            m.from.display.isEmpty
                ? '?'
                : m.from.display.characters.first.toUpperCase(),
          ),
        ),
        title: Row(
          children: [
            if (unread)
              Padding(
                padding: const EdgeInsets.only(right: 6),
                child: Icon(
                  Icons.circle,
                  size: 8,
                  color: Theme.of(context).colorScheme.primary,
                ),
              ),
            Expanded(
              child: Text(
                m.from.display,
                maxLines: 1,
                overflow: TextOverflow.ellipsis,
                style: TextStyle(fontWeight: weight),
              ),
            ),
            if (m.isExternal)
              const Padding(
                padding: EdgeInsets.only(left: 4),
                child: Chip(
                  label: Text('External'),
                  visualDensity: VisualDensity.compact,
                ),
              ),
            Text(when, style: Theme.of(context).textTheme.bodySmall),
          ],
        ),
        subtitle: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text(
              m.subject.isEmpty ? '(no subject)' : m.subject,
              maxLines: 1,
              overflow: TextOverflow.ellipsis,
              style: TextStyle(fontWeight: weight),
            ),
            if (m.snippet != null)
              Text(m.snippet!, maxLines: 1, overflow: TextOverflow.ellipsis),
          ],
        ),
        trailing: Column(
          mainAxisAlignment: MainAxisAlignment.center,
          children: [
            IconButton(
              tooltip: m.isFlagged ? 'Unstar' : 'Star',
              icon: Icon(
                m.isFlagged ? Icons.star : Icons.star_border,
                color: m.isFlagged ? Colors.amber.shade700 : null,
              ),
              onPressed: onStar,
            ),
            if (m.hasAttachments)
              const Icon(
                Icons.attach_file,
                size: 16,
                semanticLabel: 'Attachment',
              ),
          ],
        ),
      ),
    );
  }
}

class _Sidebar extends ConsumerWidget {
  const _Sidebar();

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final accounts = ref.watch(accountsProvider).value ?? const [];
    final account = ref.watch(currentAccountProvider);
    final folders = account == null
        ? null
        : ref.watch(foldersProvider(account.id));
    final current = ref.watch(currentFolderProvider);

    return ListView(
      children: [
        if (accounts.length > 1)
          Padding(
            padding: const EdgeInsets.all(12),
            child: DropdownButtonFormField<String>(
              initialValue: account?.id,
              decoration: const InputDecoration(labelText: 'Account'),
              items: [
                for (final a in accounts)
                  DropdownMenuItem(
                    value: a.id,
                    child: Text(a.email, overflow: TextOverflow.ellipsis),
                  ),
              ],
              onChanged: (id) {
                ref.read(selectedAccountIdProvider.notifier).select(id);
                ref.read(selectedFolderIdProvider.notifier).select(null);
                ref.read(selectedMessageIdProvider.notifier).select(null);
              },
            ),
          )
        else if (account != null)
          ListTile(
            title: Text(
              account.email,
              maxLines: 1,
              overflow: TextOverflow.ellipsis,
            ),
            subtitle: Text(_statusText(account)),
          ),
        const Divider(),
        ...?folders?.when(
          loading: () => [
            const Padding(
              padding: EdgeInsets.all(16),
              child: LinearProgressIndicator(),
            ),
          ],
          error: (e, _) => [
            ListTile(
              leading: const Icon(Icons.error_outline),
              title: const Text('Folders unavailable'),
              onTap: () => ref.invalidate(foldersProvider(account!.id)),
            ),
          ],
          data: (list) => [
            for (final f in list)
              ListTile(
                leading: Icon(_folderIcon(f.role)),
                title: Text(f.label),
                trailing: f.unread > 0
                    ? Text('${f.unread}', semanticsLabel: '${f.unread} unread')
                    : null,
                selected: f.id == current?.id,
                onTap: () {
                  ref.read(selectedFolderIdProvider.notifier).select(f.id);
                  ref.read(selectedMessageIdProvider.notifier).select(null);
                  if (Scaffold.maybeOf(context)?.isDrawerOpen ?? false) {
                    Navigator.pop(context);
                  }
                },
              ),
          ],
        ),
        const Divider(),
        ListTile(
          leading: const Icon(Icons.manage_accounts_outlined),
          title: const Text('Accounts'),
          onTap: () => context.push('/accounts'),
        ),
      ],
    );
  }

  static String _statusText(MailAccount a) => switch (a.status) {
    'auth_failed' => 'Password needs updating',
    'provider_unreachable' => 'Mail server unreachable',
    'throttled' => 'Provider busy — retrying',
    'disabled' => 'Disabled by administrator',
    _ =>
      a.lastSyncedAt == null
          ? 'Syncing…'
          : 'Synced ${DateFormat.Hm().format(a.lastSyncedAt!.toLocal())}',
  };

  static IconData _folderIcon(String role) => switch (role) {
    'inbox' => Icons.inbox_outlined,
    'sent' => Icons.send_outlined,
    'drafts' => Icons.drafts_outlined,
    'archive' => Icons.archive_outlined,
    'trash' => Icons.delete_outline,
    'spam' => Icons.report_gmailerrorred_outlined,
    _ => Icons.folder_outlined,
  };
}

/// Offline and provider-problem banner (FR-UI-003, US-08).
class _StatusBanner extends ConsumerWidget {
  const _StatusBanner();

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final s = ref.watch(syncStatusProvider);
    final String? text;
    if (s.offline) {
      text = 'You are offline. Showing what was loaded before.';
    } else if (s.accountsNeedingAttention.contains('auth_failed')) {
      text = 'A mailbox password needs updating. Open Accounts.';
    } else if (s.accountsNeedingAttention.isNotEmpty) {
      text =
          'The mail provider is unreachable or busy. MbunieMail is retrying.';
    } else {
      text = null;
    }
    if (text == null) return const SizedBox.shrink();
    return MaterialBanner(
      content: Text(text),
      leading: Icon(s.offline ? Icons.cloud_off : Icons.warning_amber_outlined),
      actions: [
        TextButton(
          onPressed: () => s.accountsNeedingAttention.contains('auth_failed')
              ? context.push('/accounts')
              : ref.read(syncStatusProvider.notifier).poll(),
          child: Text(
            s.accountsNeedingAttention.contains('auth_failed')
                ? 'Accounts'
                : 'Retry',
          ),
        ),
      ],
    );
  }
}

class _NoAccounts extends StatelessWidget {
  const _NoAccounts();

  @override
  Widget build(BuildContext context) => Center(
    child: Column(
      mainAxisSize: MainAxisSize.min,
      children: [
        const EmptyState(
          icon: Icons.alternate_email,
          text: 'No mailbox linked yet',
        ),
        const SizedBox(height: 12),
        FilledButton(
          onPressed: () => context.push('/accounts'),
          child: const Text('Link your company mailbox'),
        ),
      ],
    ),
  );
}

class _MailSearch extends SearchDelegate<void> {
  _MailSearch(this.ref, this.accountId)
    : super(searchFieldLabel: 'Search subject or sender');
  final WidgetRef ref;
  final String accountId;

  @override
  List<Widget> buildActions(BuildContext context) => [
    IconButton(
      tooltip: 'Clear',
      icon: const Icon(Icons.clear),
      onPressed: () => query = '',
    ),
  ];

  @override
  Widget buildLeading(BuildContext context) => IconButton(
    tooltip: 'Back',
    icon: const Icon(Icons.arrow_back),
    onPressed: () => close(context, null),
  );

  @override
  Widget buildSuggestions(BuildContext context) => const SizedBox.shrink();

  @override
  Widget buildResults(BuildContext context) {
    if (query.trim().length < 2) {
      return const EmptyState(
        icon: Icons.search,
        text: 'Type at least 2 characters',
      );
    }
    return FutureBuilder<List<MessageSummary>>(
      future: ref.read(mailRepositoryProvider).search(accountId, query),
      builder: (context, snap) {
        if (snap.hasError) {
          return ErrorRetry(error: snap.error!, onRetry: () => query = query);
        }
        if (!snap.hasData) {
          return const Center(child: CircularProgressIndicator());
        }
        final items = snap.data!;
        if (items.isEmpty) {
          return const EmptyState(icon: Icons.search_off, text: 'No matches');
        }
        return ListView(
          children: [
            for (final m in items)
              MessageTile(
                message: m,
                onTap: () => context.push('/message/${m.id}'),
                onStar: () {},
              ),
          ],
        );
      },
    );
  }
}
