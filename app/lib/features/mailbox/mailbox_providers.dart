import 'dart:async';

import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../core/errors.dart';
import '../../core/providers.dart';
import '../auth/auth_controller.dart';
import 'models.dart';

/// Signed-in user's email; every data provider depends on it, so all cached mail
/// state is discarded on sign-out or session loss (US-05).
final sessionKeyProvider = Provider<String?>(
  (ref) => switch (ref.watch(authControllerProvider)) {
    SignedIn(:final email) => email,
    _ => null,
  },
);

final accountsProvider = FutureProvider<List<MailAccount>>((ref) async {
  if (ref.watch(sessionKeyProvider) == null) return const [];
  return ref.watch(mailRepositoryProvider).accounts();
});

final selectedAccountIdProvider = NotifierProvider<_Selection, String?>(
  _Selection.new,
);
final selectedFolderIdProvider = NotifierProvider<_Selection, String?>(
  _Selection.new,
);
final selectedMessageIdProvider = NotifierProvider<_Selection, String?>(
  _Selection.new,
);

class _Selection extends Notifier<String?> {
  @override
  String? build() {
    ref.watch(sessionKeyProvider);
    return null;
  }

  void select(String? id) => state = id;
}

/// The account in use: the explicit selection, else the first linked account.
final currentAccountProvider = Provider<MailAccount?>((ref) {
  final accounts = ref.watch(accountsProvider).value ?? const [];
  final selected = ref.watch(selectedAccountIdProvider);
  return accounts.where((a) => a.id == selected).firstOrNull ??
      accounts.firstOrNull;
});

final foldersProvider = FutureProvider.family<List<MailFolder>, String>((
  ref,
  accountId,
) async {
  ref.watch(sessionKeyProvider);
  return ref.watch(mailRepositoryProvider).folders(accountId);
});

final currentFolderProvider = Provider<MailFolder?>((ref) {
  final account = ref.watch(currentAccountProvider);
  if (account == null) return null;
  final folders = ref.watch(foldersProvider(account.id)).value ?? const [];
  final selected = ref.watch(selectedFolderIdProvider);
  return folders.where((f) => f.id == selected).firstOrNull ??
      folders.where((f) => f.role == 'inbox').firstOrNull ??
      folders.firstOrNull;
});

/// Paginated message list for one folder (FR-MAIL-003): keyset cursor, pull-to-refresh,
/// optimistic flag updates.
class MessageListState {
  const MessageListState({
    this.items = const [],
    this.cursor,
    this.loading = false,
    this.error,
    this.exhausted = false,
  });
  final List<MessageSummary> items;
  final String? cursor;
  final bool loading;
  final Object? error;
  final bool exhausted;

  MessageListState copyWith({
    List<MessageSummary>? items,
    String? cursor,
    bool? loading,
    Object? error,
    bool clearError = false,
    bool? exhausted,
  }) => MessageListState(
    items: items ?? this.items,
    cursor: cursor ?? this.cursor,
    loading: loading ?? this.loading,
    error: clearError ? null : (error ?? this.error),
    exhausted: exhausted ?? this.exhausted,
  );
}

final messageListProvider =
    NotifierProvider.family<MessageListController, MessageListState, String>(
      MessageListController.new,
    );

class MessageListController extends Notifier<MessageListState> {
  MessageListController(this.folderId);
  final String folderId;

  @override
  MessageListState build() {
    ref.watch(sessionKeyProvider);
    Future.microtask(refresh);
    return const MessageListState(loading: true);
  }

  Future<void> refresh() async {
    state = state.copyWith(loading: true, clearError: true);
    try {
      final page = await ref.read(mailRepositoryProvider).messages(folderId);
      state = MessageListState(
        items: page.items,
        cursor: page.nextCursor,
        exhausted: page.nextCursor == null,
      );
    } catch (e) {
      state = state.copyWith(loading: false, error: e);
    }
  }

  Future<void> loadMore() async {
    if (state.loading || state.exhausted || state.cursor == null) return;
    state = state.copyWith(loading: true, clearError: true);
    try {
      final page = await ref
          .read(mailRepositoryProvider)
          .messages(folderId, cursor: state.cursor);
      state = MessageListState(
        items: [...state.items, ...page.items],
        cursor: page.nextCursor,
        exhausted: page.nextCursor == null,
      );
    } catch (e) {
      state = state.copyWith(loading: false, error: e);
    }
  }

  void markLocally(String id, {bool? seen, bool? flagged}) {
    state = state.copyWith(
      items: [
        for (final m in state.items)
          m.id == id ? m.copyWith(isSeen: seen, isFlagged: flagged) : m,
      ],
    );
  }

  void removeLocally(String id) => state = state.copyWith(
    items: state.items.where((m) => m.id != id).toList(),
  );

  /// Optimistic toggle; reverted if the server (or provider) refuses.
  Future<void> setFlags(MessageSummary m, {bool? seen, bool? flagged}) async {
    markLocally(m.id, seen: seen, flagged: flagged);
    try {
      await ref
          .read(mailRepositoryProvider)
          .setFlags(m.id, seen: seen, flagged: flagged);
    } catch (_) {
      markLocally(m.id, seen: m.isSeen, flagged: m.isFlagged);
      rethrow;
    }
  }
}

/// Polls `/sync/status` every 60 s while signed in (FR-SYNC-007) and tracks offline state.
class SyncStatus {
  const SyncStatus({
    this.offline = false,
    this.accountsNeedingAttention = const [],
  });
  final bool offline;
  final List<String> accountsNeedingAttention;
}

final syncStatusProvider = NotifierProvider<SyncStatusController, SyncStatus>(
  SyncStatusController.new,
);

class SyncStatusController extends Notifier<SyncStatus> {
  Timer? _timer;

  @override
  SyncStatus build() {
    if (ref.watch(sessionKeyProvider) == null) return const SyncStatus();
    _timer = Timer.periodic(const Duration(seconds: 60), (_) => poll());
    ref.onDispose(() => _timer?.cancel());
    Future.microtask(poll);
    return const SyncStatus();
  }

  Future<void> poll() async {
    try {
      final j = await ref.read(apiClientProvider).getJson('sync/status');
      final attention = (j['accounts'] as List? ?? const [])
          .map((e) => (e as Map).cast<String, dynamic>())
          .where(
            (a) => const {
              'auth_failed',
              'provider_unreachable',
              'throttled',
              'disabled',
            }.contains(a['status']),
          )
          .map((a) => '${a['status']}')
          .toList();
      state = SyncStatus(accountsNeedingAttention: attention);
    } on AppFailure catch (e) {
      if (e.isOffline) {
        state = SyncStatus(
          offline: true,
          accountsNeedingAttention: state.accountsNeedingAttention,
        );
      }
    }
  }
}
