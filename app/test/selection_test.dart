import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:mbuniemail/app.dart';
import 'package:mbuniemail/core/api/api_client.dart';
import 'package:mbuniemail/core/providers.dart';
import 'package:mbuniemail/core/storage/token_store.dart';
import 'package:mbuniemail/features/auth/auth_controller.dart';

import 'fakes.dart';

/// Gmail-style multi-select in the message list (FR-MAIL-011).
void main() {
  late FakeAdapter http;
  late MemoryTokenStore store;

  Map<String, dynamic> summary(String id, String subject, String folder) => {
    'id': id,
    'account_id': 'A1',
    'folder_id': folder,
    'thread_key': 'T$id',
    'from': {'email': 'partner@example.org', 'name': 'Partner'},
    'subject': subject,
    'snippet': 'Preview of $subject',
    'date': '2026-10-14T08:00:00Z',
    'is_seen': false,
    'is_flagged': false,
    'has_attachments': false,
    'is_external': true,
  };

  void routes({required String role}) {
    http.on(
      'GET',
      'me',
      (r) => FakeReply(200, {'email': 'developer@mt.co.tz'}),
    );
    http.on(
      'GET',
      'accounts',
      (r) => FakeReply(200, {
        'data': [
          {
            'id': 'A1',
            'email_address': 'developer@mt.co.tz',
            'status': 'active',
            'provider': 'hostinger',
          },
        ],
      }),
    );
    http.on(
      'GET',
      'accounts/A1/folders',
      (r) => FakeReply(200, {
        'data': [
          {
            'id': 'F1',
            'name': role == 'inbox' ? 'INBOX' : 'Trash',
            'role': role,
            'unread_count': 3,
            'total_count': 3,
          },
        ],
      }),
    );
    http.on(
      'GET',
      'folders/F1/messages',
      (r) => FakeReply(200, {
        'data': [
          summary('M1', 'Quarterly plan', 'F1'),
          summary('M2', 'Lunch', 'F1'),
          summary('M3', 'Invoice', 'F1'),
        ],
        'next_cursor': null,
      }),
    );
    http.on('GET', 'sync/status', (r) => FakeReply(200, {'accounts': []}));
    http.on('POST', 'messages/bulk', (r) {
      final ids = (r.data as Map)['ids'] as List;
      return FakeReply(200, {
        'results': {for (final id in ids) id: 'ok'},
      });
    });
  }

  Future<void> pumpApp(WidgetTester tester) async {
    tester.view.physicalSize = const Size(400, 800);
    tester.view.devicePixelRatio = 1;
    addTearDown(tester.view.reset);
    final api = ApiClient(config: testConfig, store: store, adapter: http);
    await tester.pumpWidget(
      ProviderScope(
        overrides: [
          configProvider.overrideWithValue(testConfig),
          tokenStoreProvider.overrideWithValue(store),
          apiClientProvider.overrideWithValue(api),
          deviceIdStoreProvider.overrideWithValue(MemoryDeviceIdStore()),
        ],
        child: const MbunieMailApp(),
      ),
    );
    await tester.pumpAndSettle();
  }

  Map<String, dynamic> bulkBody() =>
      (http.requests.lastWhere((r) => r.path.endsWith('messages/bulk')).data
              as Map)
          .cast<String, dynamic>();

  setUp(() {
    http = FakeAdapter();
    store = MemoryTokenStore()..value = session();
  });

  testWidgets('long-press selects, tap adds, delete moves all to Trash', (
    tester,
  ) async {
    routes(role: 'inbox');
    await pumpApp(tester);

    await tester.longPress(find.text('Quarterly plan'));
    await tester.pumpAndSettle();
    expect(find.byTooltip('Clear selection'), findsOneWidget);
    await tester.tap(find.text('Invoice')); // in selection mode a tap selects
    await tester.pumpAndSettle();
    expect(find.bySemanticsLabel('2 selected'), findsOneWidget);

    await tester.tap(find.byTooltip('Delete'));
    await tester.pumpAndSettle();

    expect(bulkBody(), {
      'ids': ['M1', 'M3'],
      'action': 'trash',
    });
    expect(find.text('Quarterly plan'), findsNothing);
    expect(find.text('Invoice'), findsNothing);
    expect(find.text('Lunch'), findsOneWidget);
    expect(find.text('2 moved to Trash'), findsOneWidget);
    expect(find.byTooltip('Clear selection'), findsNothing);
  });

  testWidgets('select all in Trash asks before deleting forever', (
    tester,
  ) async {
    routes(role: 'trash');
    await pumpApp(tester);

    await tester.longPress(find.text('Lunch'));
    await tester.pumpAndSettle();
    await tester.tap(find.byTooltip('Select all'));
    await tester.pumpAndSettle();
    expect(find.bySemanticsLabel('3 selected'), findsOneWidget);

    await tester.tap(find.byTooltip('Delete forever'));
    await tester.pumpAndSettle();
    expect(find.text('Delete 3 messages forever?'), findsOneWidget);
    await tester.tap(find.widgetWithText(FilledButton, 'Delete forever'));
    await tester.pumpAndSettle();

    expect(bulkBody()['ids'], unorderedEquals(['M1', 'M2', 'M3']));
    expect(find.text('3 deleted forever'), findsOneWidget);
    expect(find.text('No messages here'), findsOneWidget);
  });
}
