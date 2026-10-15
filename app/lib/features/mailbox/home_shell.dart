import 'package:flutter/material.dart';
import 'package:flutter/rendering.dart';
import 'package:flutter/services.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:intl/intl.dart';

import '../../core/providers.dart';
import '../../shared/avatar.dart';
import '../../shared/motion.dart';
import '../../shared/widgets.dart';
import '../compose/compose_screen.dart';
import '../reader/reader_screen.dart';
import 'account_switcher.dart';
import 'mailbox_providers.dart';
import 'models.dart';

/// Adaptive mailbox (FR-UI-001), laid out like Gmail: below 600 dp a search bar,
/// drawer and list; from 600 dp a permanent sidebar; from 1100 dp the reader opens
/// beside the list.
class HomeShell extends ConsumerStatefulWidget {
  const HomeShell({super.key});

  static const compact = 600.0;
  static const expanded = 1100.0;

  static Future<void> refresh(WidgetRef ref) async {
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

  static Future<void> openSearch(BuildContext context, WidgetRef ref) async {
    final account = ref.read(currentAccountProvider);
    if (account == null) return;
    await showSearch<void>(
      context: context,
      delegate: _MailSearch(ref, account.id),
    );
  }

  @override
  ConsumerState<HomeShell> createState() => _HomeShellState();
}

class _HomeShellState extends ConsumerState<HomeShell> {
  bool _fabExtended = true;

  void _compose() => context.push('/compose', extra: const ComposeDraft());

  @override
  Widget build(BuildContext context) {
    final accounts = ref.watch(accountsProvider);
    return LayoutBuilder(
      builder: (context, c) {
        final width = c.maxWidth;
        final phone = width < HomeShell.compact;
        final threePane = width >= HomeShell.expanded;

        void openMessage(MessageSummary m) {
          ref.read(selectedMessageIdProvider.notifier).select(m.id);
          if (!threePane) context.push('/message/${m.id}');
        }

        final body = accounts.when(
          loading: () => const SkeletonList(label: 'Loading accounts'),
          error: (e, _) => ErrorRetry(
            error: e,
            onRetry: () => ref.invalidate(accountsProvider),
          ),
          data: (list) => list.isEmpty
              ? const _NoAccounts()
              : _MailArea(threePane: threePane, onOpen: openMessage),
        );

        final content = NotificationListener<UserScrollNotification>(
          onNotification: (n) {
            final extend = n.direction != ScrollDirection.reverse;
            if (n.direction != ScrollDirection.idle && extend != _fabExtended) {
              setState(() => _fabExtended = extend);
            }
            return false;
          },
          child: Column(
            children: [
              const _StatusBanner(),
              Expanded(child: body),
            ],
          ),
        );

        return CallbackShortcuts(
          bindings: {
            const SingleActivator(LogicalKeyboardKey.keyC): _compose,
            const SingleActivator(LogicalKeyboardKey.f5): () =>
                HomeShell.refresh(ref),
            const SingleActivator(LogicalKeyboardKey.keyR, control: true): () =>
                HomeShell.refresh(ref),
            const SingleActivator(LogicalKeyboardKey.slash): () =>
                HomeShell.openSearch(context, ref),
          },
          child: Focus(
            autofocus: true,
            child: phone
                ? Scaffold(
                    drawer: const Drawer(child: SafeArea(child: MailSidebar())),
                    body: SafeArea(
                      bottom: false,
                      child: Column(
                        children: [
                          const _SearchHeader(),
                          Expanded(child: content),
                        ],
                      ),
                    ),
                    floatingActionButton: FloatingActionButton.extended(
                      onPressed: _compose,
                      isExtended: _fabExtended,
                      icon: const Icon(Icons.edit_outlined),
                      label: const Text('Compose'),
                      tooltip: 'Compose (C)',
                    ),
                  )
                : Scaffold(
                    backgroundColor: Theme.of(context)
                        .colorScheme
                        .surfaceContainerLow,
                    body: SafeArea(
                      child: Row(
                        children: [
                          SizedBox(
                            width: 272,
                            child: MailSidebar(onCompose: _compose),
                          ),
                          Expanded(
                            child: Padding(
                              padding: const EdgeInsets.fromLTRB(0, 8, 12, 12),
                              child: Column(
                                children: [
                                  const _SearchHeader(wide: true),
                                  const SizedBox(height: 8),
                                  Expanded(
                                    child: ClipRRect(
                                      borderRadius: BorderRadius.circular(20),
                                      child: ColoredBox(
                                        color: Theme.of(context)
                                            .colorScheme
                                            .surfaceContainerLowest,
                                        child: content,
                                      ),
                                    ),
                                  ),
                                ],
                              ),
                            ),
                          ),
                        ],
                      ),
                    ),
                  ),
          ),
        );
      },
    );
  }
}

/// Gmail-style pill: menu (phone), "Search in mail", and the account avatar that
/// opens the account switcher.
class _SearchHeader extends ConsumerWidget {
  const _SearchHeader({this.wide = false});
  final bool wide;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final scheme = Theme.of(context).colorScheme;
    final account = ref.watch(currentAccountProvider);
    final me = ref.watch(sessionKeyProvider) ?? '';
    return Padding(
      padding: wide ? EdgeInsets.zero : const EdgeInsets.fromLTRB(12, 8, 12, 4),
      child: Material(
        color: wide ? scheme.surfaceContainerHigh : scheme.surfaceContainer,
        borderRadius: BorderRadius.circular(28),
        child: InkWell(
          borderRadius: BorderRadius.circular(28),
          onTap: () => HomeShell.openSearch(context, ref),
          child: SizedBox(
            height: 56,
            child: Row(
              children: [
                const SizedBox(width: 4),
                if (!wide)
                  IconButton(
                    tooltip: 'Open navigation menu',
                    icon: const Icon(Icons.menu),
                    onPressed: () => Scaffold.of(context).openDrawer(),
                  )
                else
                  const Padding(
                    padding: EdgeInsets.symmetric(horizontal: 12),
                    child: Icon(Icons.search),
                  ),
                const SizedBox(width: 4),
                Expanded(
                  child: Text(
                    'Search in mail',
                    style: Theme.of(context).textTheme.bodyLarge
                        ?.copyWith(color: scheme.onSurfaceVariant),
                  ),
                ),
                if (wide)
                  IconButton(
                    tooltip: 'Refresh (F5)',
                    icon: const Icon(Icons.refresh),
                    onPressed: () => HomeShell.refresh(ref),
                  ),
                IconButton(
                  tooltip: 'Settings',
                  icon: const Icon(Icons.settings_outlined),
                  onPressed: () => context.push('/settings'),
                ),
                Padding(
                  padding: const EdgeInsets.only(right: 8),
                  child: Tooltip(
                    message: 'Switch account',
                    child: InkResponse(
                      radius: 24,
                      onTap: () => showAccountSwitcher(context, ref),
                      child: Hero(
                        tag: 'account-avatar',
                        child: LetterAvatar(
                          key: ValueKey(account?.id),
                          name: account?.displayName ?? '',
                          email: account?.email ?? me,
                          radius: 17,
                        ),
                      ),
                    ),
                  ),
                ),
              ],
            ),
          ),
        ),
      ),
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
    if (folder == null) return const SkeletonList(label: 'Loading folders');
    final list = AnimatedSwitcher(
      duration: Motion.medium,
      child: MessageList(
        key: ValueKey(folder.id),
        folder: folder,
        onOpen: onOpen,
      ),
    );
    if (!threePane) return list;
    final selected = ref.watch(selectedMessageIdProvider);
    return Row(
      children: [
        SizedBox(width: 420, child: list),
        const VerticalDivider(width: 1),
        Expanded(
          child: AnimatedSwitcher(
            duration: Motion.medium,
            child: selected == null
                ? const EmptyState(
                    icon: Icons.mark_email_read_outlined,
                    text: 'Select a message to read',
                  )
                : ReaderView(key: ValueKey(selected), messageId: selected),
          ),
        ),
      ],
    );
  }
}

class MessageList extends ConsumerWidget {
  const MessageList({super.key, required this.folder, required this.onOpen});
  final MailFolder folder;
  final void Function(MessageSummary) onOpen;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final s = ref.watch(messageListProvider(folder.id));
    final ctrl = ref.read(messageListProvider(folder.id).notifier);
    final account = ref.watch(currentAccountProvider);
    final folders = account == null
        ? const <MailFolder>[]
        : ref.watch(foldersProvider(account.id)).value ?? const <MailFolder>[];
    final archive = folders.where((f) => f.role == 'archive').firstOrNull;
    final canTrash = !const {'trash', 'spam'}.contains(folder.role);
    final canArchive = archive != null && archive.id != folder.id;

    if (s.items.isEmpty && s.loading) {
      return const SkeletonList(label: 'Loading messages');
    }
    if (s.items.isEmpty && s.error != null) {
      return ErrorRetry(error: s.error!, onRetry: ctrl.refresh);
    }

    Future<void> remove(MessageSummary m, {required bool toArchive}) async {
      final messenger = ScaffoldMessenger.of(context);
      ctrl.removeLocally(m.id);
      if (ref.read(selectedMessageIdProvider) == m.id) {
        ref.read(selectedMessageIdProvider.notifier).select(null);
      }
      try {
        final repo = ref.read(mailRepositoryProvider);
        toArchive ? await repo.move(m.id, archive!.id) : await repo.trash(m.id);
        messenger.showSnackBar(
          SnackBar(
            content: Text(toArchive ? 'Archived' : 'Moved to Trash'),
            duration: const Duration(seconds: 3),
          ),
        );
      } catch (e) {
        await ctrl.refresh();
        if (context.mounted) showFailure(context, e);
      }
    }

    return RefreshIndicator(
      onRefresh: () => HomeShell.refresh(ref),
      child: s.items.isEmpty
          ? ListView(
              children: [
                const SizedBox(height: 120),
                EmptyState(
                  icon: folder.role == 'inbox'
                      ? Icons.celebration_outlined
                      : Icons.inbox_outlined,
                  text: folder.role == 'inbox'
                      ? 'You’re all caught up'
                      : 'No messages here',
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
              child: ListView.builder(
                padding: const EdgeInsets.only(bottom: 96),
                itemCount: s.items.length + (s.exhausted ? 0 : 1) + 1,
                itemBuilder: (context, i) {
                  if (i == 0) return _FolderHeading(folder: folder);
                  i -= 1;
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
                  final tile = MessageTile(
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
                  return EnterAnimation(
                    key: ValueKey(m.id),
                    index: i < 12 ? i : 0,
                    child: !canTrash && !canArchive
                        ? tile
                        : Dismissible(
                            key: ValueKey('swipe-${m.id}'),
                            direction: canArchive && canTrash
                                ? DismissDirection.horizontal
                                : canArchive
                                ? DismissDirection.startToEnd
                                : DismissDirection.endToStart,
                            background: const _SwipeBackground(archive: true),
                            secondaryBackground: const _SwipeBackground(
                              archive: false,
                            ),
                            onDismissed: (d) => remove(
                              m,
                              toArchive:
                                  canArchive &&
                                  d == DismissDirection.startToEnd,
                            ),
                            child: tile,
                          ),
                  );
                },
              ),
            ),
    );
  }
}

class _FolderHeading extends StatelessWidget {
  const _FolderHeading({required this.folder});
  final MailFolder folder;

  @override
  Widget build(BuildContext context) => Padding(
    padding: const EdgeInsets.fromLTRB(20, 12, 20, 4),
    child: Row(
      children: [
        Text(
          folder.label,
          style: Theme.of(context).textTheme.labelLarge
              ?.copyWith(color: Theme.of(context).colorScheme.onSurfaceVariant),
        ),
        const Spacer(),
        if (folder.unread > 0)
          Text(
            '${folder.unread} unread',
            style: Theme.of(context).textTheme.labelMedium
                ?.copyWith(color: Theme.of(context).colorScheme.primary),
          ),
      ],
    ),
  );
}

class _SwipeBackground extends StatelessWidget {
  const _SwipeBackground({required this.archive});
  final bool archive;

  @override
  Widget build(BuildContext context) {
    final color = archive ? const Color(0xFF15803D) : const Color(0xFFB3261E);
    return Container(
      color: color,
      alignment: archive ? Alignment.centerLeft : Alignment.centerRight,
      padding: const EdgeInsets.symmetric(horizontal: 24),
      child: Row(
        mainAxisSize: MainAxisSize.min,
        children: [
          Icon(
            archive ? Icons.archive_outlined : Icons.delete_outline,
            color: Colors.white,
          ),
          const SizedBox(width: 8),
          Text(
            archive ? 'Archive' : 'Delete',
            style: const TextStyle(
              color: Colors.white,
              fontWeight: FontWeight.w600,
            ),
          ),
        ],
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
    final theme = Theme.of(context);
    final scheme = theme.colorScheme;
    final unread = !m.isSeen;
    final strong = unread ? FontWeight.w700 : FontWeight.w400;
    final now = DateTime.now();
    final local = m.date.toLocal();
    final when =
        local.year == now.year &&
            local.month == now.month &&
            local.day == now.day
        ? DateFormat.Hm().format(local)
        : local.year == now.year
        ? DateFormat.MMMd().format(local)
        : DateFormat.yMd().format(local);
    final muted = scheme.onSurfaceVariant;

    return Semantics(
      label:
          '${unread ? 'Unread. ' : ''}From ${m.from.display}. ${m.subject}. $when${m.hasAttachments ? '. Has attachments' : ''}',
      excludeSemantics: true,
      button: true,
      selected: selected,
      child: Padding(
        padding: const EdgeInsets.symmetric(horizontal: 8),
        child: Material(
          color: selected ? scheme.secondaryContainer : Colors.transparent,
          borderRadius: BorderRadius.circular(18),
          child: InkWell(
            borderRadius: BorderRadius.circular(18),
            onTap: onTap,
            child: Padding(
              padding: const EdgeInsets.fromLTRB(12, 10, 4, 10),
              child: Row(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Padding(
                    padding: const EdgeInsets.only(top: 2),
                    child: LetterAvatar(
                      name: m.from.name ?? '',
                      email: m.from.email,
                    ),
                  ),
                  const SizedBox(width: 14),
                  Expanded(
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Row(
                          children: [
                            Expanded(
                              child: Text(
                                m.from.display,
                                maxLines: 1,
                                overflow: TextOverflow.ellipsis,
                                style: theme.textTheme.titleSmall?.copyWith(
                                  fontWeight: strong,
                                  fontSize: 15,
                                ),
                              ),
                            ),
                            if (m.isExternal)
                              Container(
                                margin: const EdgeInsets.only(left: 6),
                                padding: const EdgeInsets.symmetric(
                                  horizontal: 6,
                                  vertical: 1,
                                ),
                                decoration: BoxDecoration(
                                  borderRadius: BorderRadius.circular(6),
                                  border: Border.all(
                                    color: scheme.outlineVariant,
                                  ),
                                ),
                                child: Text(
                                  'External',
                                  style: theme.textTheme.labelSmall?.copyWith(
                                    color: muted,
                                  ),
                                ),
                              ),
                            const SizedBox(width: 8),
                            Text(
                              when,
                              style: theme.textTheme.labelMedium?.copyWith(
                                color: unread ? scheme.primary : muted,
                                fontWeight: strong,
                              ),
                            ),
                          ],
                        ),
                        const SizedBox(height: 2),
                        Text(
                          m.subject.isEmpty ? '(no subject)' : m.subject,
                          maxLines: 1,
                          overflow: TextOverflow.ellipsis,
                          style: theme.textTheme.bodyMedium?.copyWith(
                            fontWeight: strong,
                            color: unread ? scheme.onSurface : muted,
                          ),
                        ),
                        Row(
                          children: [
                            Expanded(
                              child: Text(
                                m.snippet ?? '',
                                maxLines: 1,
                                overflow: TextOverflow.ellipsis,
                                style: theme.textTheme.bodyMedium?.copyWith(
                                  color: muted,
                                ),
                              ),
                            ),
                            if (m.hasAttachments)
                              Padding(
                                padding: const EdgeInsets.only(left: 4),
                                child: Icon(
                                  Icons.attach_file,
                                  size: 16,
                                  color: muted,
                                  semanticLabel: 'Attachment',
                                ),
                              ),
                          ],
                        ),
                      ],
                    ),
                  ),
                  IconButton(
                    tooltip: m.isFlagged ? 'Unstar' : 'Star',
                    onPressed: onStar,
                    icon: AnimatedSwitcher(
                      duration: Motion.fast,
                      transitionBuilder: (child, a) => ScaleTransition(
                        scale: CurvedAnimation(
                          parent: a,
                          curve: Curves.easeOutBack,
                        ),
                        child: child,
                      ),
                      child: Icon(
                        m.isFlagged ? Icons.star : Icons.star_border,
                        key: ValueKey(m.isFlagged),
                        color: m.isFlagged ? const Color(0xFFF4B400) : muted,
                      ),
                    ),
                  ),
                ],
              ),
            ),
          ),
        ),
      ),
    );
  }
}

/// Folders and app navigation. Permanent on tablets/desktop; a drawer on phones.
class MailSidebar extends ConsumerWidget {
  const MailSidebar({super.key, this.onCompose});
  final VoidCallback? onCompose;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final theme = Theme.of(context);
    final account = ref.watch(currentAccountProvider);
    final folders = account == null
        ? null
        : ref.watch(foldersProvider(account.id));
    final current = ref.watch(currentFolderProvider);

    void closeDrawer() {
      if (Scaffold.maybeOf(context)?.isDrawerOpen ?? false) {
        Navigator.pop(context);
      }
    }

    return ListView(
      padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 8),
      children: [
        Padding(
          padding: const EdgeInsets.fromLTRB(12, 8, 12, 16),
          child: Row(
            children: [
              Container(
                width: 36,
                height: 36,
                decoration: BoxDecoration(
                  color: theme.colorScheme.primary,
                  borderRadius: BorderRadius.circular(10),
                ),
                child: Icon(
                  Icons.mail,
                  color: theme.colorScheme.onPrimary,
                  size: 20,
                ),
              ),
              const SizedBox(width: 12),
              Flexible(
                child: Text(
                  'MbunieMail',
                  maxLines: 1,
                  overflow: TextOverflow.ellipsis,
                  style: theme.textTheme.titleLarge?.copyWith(
                    fontWeight: FontWeight.w700,
                  ),
                ),
              ),
            ],
          ),
        ),
        if (onCompose != null)
          Padding(
            padding: const EdgeInsets.fromLTRB(4, 0, 64, 16),
            child: FloatingActionButton.extended(
              heroTag: null,
              elevation: 0,
              onPressed: onCompose,
              icon: const Icon(Icons.edit_outlined),
              label: const Text('Compose'),
              tooltip: 'Compose (C)',
            ),
          ),
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
            for (final f in _ordered(list))
              _NavItem(
                icon: _folderIcon(f.role, false),
                selectedIcon: _folderIcon(f.role, true),
                label: f.label,
                count: f.unread,
                selected: f.id == current?.id,
                onTap: () {
                  ref.read(selectedFolderIdProvider.notifier).select(f.id);
                  ref.read(selectedMessageIdProvider.notifier).select(null);
                  closeDrawer();
                },
              ),
          ],
        ),
        const Padding(
          padding: EdgeInsets.symmetric(vertical: 8),
          child: Divider(),
        ),
        _NavItem(
          icon: Icons.manage_accounts_outlined,
          label: 'Accounts',
          onTap: () {
            closeDrawer();
            context.push('/accounts');
          },
        ),
        _NavItem(
          icon: Icons.settings_outlined,
          label: 'Settings',
          onTap: () {
            closeDrawer();
            context.push('/settings');
          },
        ),
      ],
    );
  }

  static List<MailFolder> _ordered(List<MailFolder> list) {
    const order = ['inbox', 'drafts', 'sent', 'archive', 'spam', 'trash'];
    int rank(MailFolder f) {
      final i = order.indexOf(f.role);
      return i < 0 ? order.length : i;
    }

    return [...list]..sort((a, b) {
      final r = rank(a).compareTo(rank(b));
      return r != 0
          ? r
          : a.label.toLowerCase().compareTo(b.label.toLowerCase());
    });
  }

  static IconData _folderIcon(String role, bool filled) => switch (role) {
    'inbox' => filled ? Icons.inbox : Icons.inbox_outlined,
    'sent' => filled ? Icons.send : Icons.send_outlined,
    'drafts' =>
      filled ? Icons.insert_drive_file : Icons.insert_drive_file_outlined,
    'archive' => filled ? Icons.archive : Icons.archive_outlined,
    'trash' => filled ? Icons.delete : Icons.delete_outline,
    'spam' => filled ? Icons.report : Icons.report_gmailerrorred_outlined,
    _ => filled ? Icons.label : Icons.label_outline,
  };
}

class _NavItem extends StatelessWidget {
  const _NavItem({
    required this.icon,
    required this.label,
    required this.onTap,
    this.selectedIcon,
    this.count = 0,
    this.selected = false,
  });
  final IconData icon;
  final IconData? selectedIcon;
  final String label;
  final int count;
  final bool selected;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    final scheme = Theme.of(context).colorScheme;
    final fg = selected ? scheme.onSecondaryContainer : scheme.onSurfaceVariant;
    return Semantics(
      selected: selected,
      button: true,
      label: count > 0 ? '$label, $count unread' : label,
      excludeSemantics: true,
      child: Padding(
        padding: const EdgeInsets.symmetric(vertical: 1),
        child: AnimatedContainer(
          duration: Motion.fast,
          curve: Motion.curve,
          decoration: BoxDecoration(
            color: selected ? scheme.secondaryContainer : Colors.transparent,
            borderRadius: BorderRadius.circular(28),
          ),
          child: InkWell(
            borderRadius: BorderRadius.circular(28),
            onTap: onTap,
            child: SizedBox(
              height: 48,
              child: Row(
                children: [
                  const SizedBox(width: 16),
                  Icon(
                    selected ? (selectedIcon ?? icon) : icon,
                    color: fg,
                    size: 22,
                  ),
                  const SizedBox(width: 16),
                  Expanded(
                    child: Text(
                      label,
                      maxLines: 1,
                      overflow: TextOverflow.ellipsis,
                      style: TextStyle(
                        color: fg,
                        fontWeight: selected || count > 0
                            ? FontWeight.w700
                            : FontWeight.w500,
                      ),
                    ),
                  ),
                  if (count > 0)
                    Text(
                      count > 999 ? '999+' : '$count',
                      style: TextStyle(
                        color: fg,
                        fontWeight: FontWeight.w700,
                        fontSize: 13,
                      ),
                    ),
                  const SizedBox(width: 20),
                ],
              ),
            ),
          ),
        ),
      ),
    );
  }
}

/// Offline and provider-problem banner (FR-UI-003, US-08).
class _StatusBanner extends ConsumerWidget {
  const _StatusBanner();

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final s = ref.watch(syncStatusProvider);
    final scheme = Theme.of(context).colorScheme;
    final authFailed = s.accountsNeedingAttention.contains('auth_failed');
    final String? text;
    if (s.offline) {
      text = 'You’re offline. Showing what was loaded before.';
    } else if (authFailed) {
      text = 'A mailbox password needs updating.';
    } else if (s.accountsNeedingAttention.isNotEmpty) {
      text = 'The mail server is busy or unreachable. Retrying…';
    } else {
      text = null;
    }
    return AnimatedSize(
      duration: Motion.medium,
      curve: Motion.curve,
      child: text == null
          ? const SizedBox(width: double.infinity)
          : Container(
              width: double.infinity,
              margin: const EdgeInsets.fromLTRB(12, 8, 12, 4),
              padding: const EdgeInsets.fromLTRB(16, 6, 6, 6),
              decoration: BoxDecoration(
                color: s.offline
                    ? scheme.surfaceContainerHigh
                    : scheme.errorContainer,
                borderRadius: BorderRadius.circular(16),
              ),
              child: Row(
                children: [
                  Icon(
                    s.offline ? Icons.cloud_off : Icons.warning_amber_rounded,
                    size: 20,
                    color: s.offline
                        ? scheme.onSurfaceVariant
                        : scheme.onErrorContainer,
                  ),
                  const SizedBox(width: 12),
                  Expanded(child: Text(text)),
                  TextButton(
                    onPressed: () => authFailed
                        ? context.push('/accounts')
                        : ref.read(syncStatusProvider.notifier).poll(),
                    child: Text(authFailed ? 'Fix' : 'Retry'),
                  ),
                ],
              ),
            ),
    );
  }
}

class _NoAccounts extends StatelessWidget {
  const _NoAccounts();

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    return Center(
      child: SingleChildScrollView(
        padding: const EdgeInsets.all(32),
        child: EnterAnimation(
          child: Column(
            mainAxisSize: MainAxisSize.min,
            children: [
              Container(
                width: 96,
                height: 96,
                decoration: BoxDecoration(
                  color: theme.colorScheme.primaryContainer,
                  shape: BoxShape.circle,
                ),
                child: Icon(
                  Icons.alternate_email,
                  size: 44,
                  color: theme.colorScheme.onPrimaryContainer,
                ),
              ),
              const SizedBox(height: 20),
              Text('No mailbox linked yet', style: theme.textTheme.titleLarge),
              const SizedBox(height: 8),
              Text(
                'Link your company mailbox to start reading and sending mail.',
                textAlign: TextAlign.center,
                style: theme.textTheme.bodyMedium?.copyWith(
                  color: theme.colorScheme.onSurfaceVariant,
                ),
              ),
              const SizedBox(height: 20),
              FilledButton.icon(
                onPressed: () => context.push('/accounts?add=1'),
                icon: const Icon(Icons.add),
                label: const Text('Link your company mailbox'),
              ),
            ],
          ),
        ),
      ),
    );
  }
}

class _MailSearch extends SearchDelegate<void> {
  _MailSearch(this.ref, this.accountId)
    : super(searchFieldLabel: 'Search subject or sender');
  final WidgetRef ref;
  final String accountId;

  @override
  ThemeData appBarTheme(BuildContext context) {
    final theme = Theme.of(context);
    return theme.copyWith(
      inputDecorationTheme: const InputDecorationTheme(
        border: InputBorder.none,
        filled: false,
      ),
    );
  }

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
  Widget buildSuggestions(BuildContext context) => const EmptyState(
    icon: Icons.manage_search,
    text: 'Search by subject, or type an address',
  );

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
        if (!snap.hasData) return const SkeletonList(label: 'Searching');
        final items = snap.data!;
        if (items.isEmpty) {
          return const EmptyState(icon: Icons.search_off, text: 'No matches');
        }
        return ListView(
          children: [
            for (final (i, m) in items.indexed)
              EnterAnimation(
                index: i,
                child: MessageTile(
                  message: m,
                  onTap: () => context.push('/message/${m.id}'),
                  onStar: () {},
                ),
              ),
          ],
        );
      },
    );
  }
}
