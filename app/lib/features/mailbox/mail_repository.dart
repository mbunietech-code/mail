import 'dart:typed_data';

import '../../core/api/api_client.dart';
import '../../core/ids.dart';
import 'models.dart';

/// All mail endpoints (docs/API_SPECIFICATION.md §3–11).
class MailRepository {
  MailRepository(this.api);
  final ApiClient api;

  Future<List<MailAccount>> accounts() async =>
      ((await api.getJson('accounts'))['data'] as List)
          .map((e) => MailAccount.fromJson((e as Map).cast<String, dynamic>()))
          .toList();

  Future<MailAccount> linkAccount({
    required String email,
    required String password,
    String? displayName,
  }) async => MailAccount.fromJson(
    await api.postJson('accounts', {
      'email_address': email,
      'password': password,
      'display_name': displayName,
    }),
  );

  Future<void> unlinkAccount(String id) => api.delete('accounts/$id');

  Future<void> syncNow(String accountId) =>
      api.postJson('accounts/$accountId/sync');

  Future<List<MailFolder>> folders(String accountId) async =>
      ((await api.getJson('accounts/$accountId/folders'))['data'] as List)
          .map((e) => MailFolder.fromJson((e as Map).cast<String, dynamic>()))
          .toList();

  Future<MessagePage> messages(
    String folderId, {
    String? cursor,
    int limit = 50,
    bool unreadOnly = false,
  }) async {
    final j = await api.getJson(
      'folders/$folderId/messages',
      query: {'limit': limit, 'cursor': ?cursor, if (unreadOnly) 'unread': 1},
    );
    return MessagePage(
      (j['data'] as List)
          .map(
            (e) => MessageSummary.fromJson((e as Map).cast<String, dynamic>()),
          )
          .toList(),
      j['next_cursor'] as String?,
    );
  }

  Future<List<MessageSummary>> search(String accountId, String text) async {
    final q = text.trim();
    final j = await api.getJson(
      'accounts/$accountId/search',
      query: {q.contains('@') ? 'from' : 'subject': q},
    );
    return (j['data'] as List)
        .map((e) => MessageSummary.fromJson((e as Map).cast<String, dynamic>()))
        .toList();
  }

  Future<MessageDetail> message(String id, {bool allowImages = false}) async =>
      MessageDetail.fromJson(
        await api.getJson(
          'messages/$id',
          query: allowImages ? {'images': 'allow'} : null,
        ),
      );

  Future<void> setFlags(String id, {bool? seen, bool? flagged}) =>
      api.patchJson('messages/$id', {'is_seen': ?seen, 'is_flagged': ?flagged});

  Future<void> trash(String id) => api.delete('messages/$id');

  Future<void> move(String id, String folderId) =>
      api.postJson('messages/$id/move', {'folder_id': folderId});

  Future<void> markSpam(String id, bool spam) =>
      api.postJson('messages/$id/spam', {'spam': spam});

  Future<List<MessageSummary>> thread(
    String accountId,
    String threadKey,
  ) async {
    final j = await api.getJson(
      'threads/$threadKey',
      query: {'account_id': accountId},
    );
    return (j['data'] as List)
        .map((e) => MessageSummary.fromJson((e as Map).cast<String, dynamic>()))
        .toList();
  }

  Future<Uint8List> attachment(String id) => api.getBytes('attachments/$id');

  Future<Map<String, dynamic>> uploadAttachment(
    String filename,
    Stream<List<int>> content,
    int length,
  ) => api.upload('outbox/attachments', filename, content, length);

  /// The Idempotency-Key must be generated once per compose and reused on retries, so a
  /// network failure followed by a retry can never send twice (FR-SEND-005).
  Future<Map<String, dynamic>> send(
    Map<String, dynamic> body, {
    required String idempotencyKey,
  }) => api.postJson('outbox/send', body, {'Idempotency-Key': idempotencyKey});

  Future<Map<String, dynamic>> outboxStatus(String id) =>
      api.getJson('outbox/$id');

  Future<List<Address>> suggest(String q) async =>
      ((await api.getJson('contacts/suggest', query: {'q': q}))['data'] as List)
          .map((e) => Address.fromJson((e as Map).cast<String, dynamic>()))
          .toList();

  static String newIdempotencyKey() => 'send-${newClientId()}';
}
