import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:mbuniemail/app.dart';
import 'package:mbuniemail/core/api/api_client.dart';
import 'package:mbuniemail/core/providers.dart';
import 'package:mbuniemail/core/storage/token_store.dart';
import 'package:mbuniemail/features/auth/auth_controller.dart';

import 'fakes.dart';

/// Creating, renaming and deleting custom folders from the drawer (FR-MAIL-002).
void main() {
  late FakeAdapter http;
  late MemoryTokenStore store;
  late List<Map<String, Object>> folders;

  void routes() {
    folders = [
      {'id': 'F1', 'name': 'INBOX', 'role': 'inbox', 'unread_count': 0},
      {
        'id': 'F9',
        'name': 'INBOX.Projects',
        'role': 'custom',
        'total_count': 4,
      },
    ];
    http.on('GET', 'me', (r) => FakeReply(200, {'email': 'dev@mt.co.tz'}));
    http.on(
      'GET',
      'accounts',
      (r) => FakeReply(200, {
        'data': [
          {
            'id': 'A1',
            'email_address': 'dev@mt.co.tz',
            'status': 'active',
            'provider': 'hostinger',
          },
        ],
      }),
    );
    http.on(
      'GET',
      'accounts/A1/folders',
      (r) => FakeReply(200, {'data': folders}),
    );
    http.on(
      'GET',
      'folders/F1/messages',
      (r) => FakeReply(200, {'data': [], 'next_cursor': null}),
    );
    http.on('GET', 'sync/status', (r) => FakeReply(200, {'accounts': []}));
    http.on('POST', 'accounts/A1/folders', (r) {
      folders.add({
        'id': 'F10',
        'name': (r.data as Map)['name'],
        'role': 'custom',
      });
      return FakeReply(201, {'id': 'F10'});
    });
    http.on('PATCH', 'folders/F9', (r) {
      folders[1] = {
        'id': 'F9',
        'name': 'INBOX.${(r.data as Map)['name']}',
        'role': 'custom',
      };
      return FakeReply(200, {'id': 'F9'});
    });
    http.on('DELETE', 'folders/F9', (r) {
      folders.removeAt(1);
      return FakeReply(204);
    });
  }

  Future<void> openDrawer(WidgetTester tester) async {
    final api = ApiClient(config: testConfig, store: store, adapter: http);
    tester.view.physicalSize = const Size(400, 800);
    tester.view.devicePixelRatio = 1;
    addTearDown(tester.view.reset);
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
    tester.state<ScaffoldState>(find.byType(Scaffold).first).openDrawer();
    await tester.pumpAndSettle();
  }

  setUp(() {
    http = FakeAdapter();
    store = MemoryTokenStore()..value = session();
    routes();
  });

  testWidgets('a new folder appears after creating it', (tester) async {
    await openDrawer(tester);

    await tester.tap(find.byTooltip('New folder'));
    await tester.pumpAndSettle();
    await tester.enterText(find.byType(TextFormField), 'Clients');
    await tester.tap(find.widgetWithText(FilledButton, 'Create'));
    await tester.pumpAndSettle();

    expect(find.text('Clients'), findsWidgets);
    expect(find.text('Folder “Clients” created'), findsOneWidget);
  });

  testWidgets('only custom folders offer rename, which updates the list', (
    tester,
  ) async {
    await openDrawer(tester);
    expect(find.byTooltip('Folder options'), findsOneWidget); // not Inbox

    await tester.tap(find.byTooltip('Folder options'));
    await tester.pumpAndSettle();
    await tester.tap(find.text('Rename'));
    await tester.pumpAndSettle();
    await tester.enterText(find.byType(TextFormField), 'Archive 2026');
    await tester.tap(find.widgetWithText(FilledButton, 'Rename'));
    await tester.pumpAndSettle();

    expect(
      (http.requests.lastWhere((r) => r.method == 'PATCH').data as Map)['name'],
      'Archive 2026',
    );
    expect(find.text('Archive 2026'), findsWidgets);
    expect(find.text('Projects'), findsNothing);
  });

  testWidgets('delete warns that messages go to Trash', (tester) async {
    await openDrawer(tester);

    await tester.tap(find.byTooltip('Folder options'));
    await tester.pumpAndSettle();
    await tester.tap(find.text('Delete folder'));
    await tester.pumpAndSettle();
    expect(find.text('Its 4 messages will be moved to Trash.'), findsOneWidget);
    await tester.tap(find.widgetWithText(FilledButton, 'Delete'));
    await tester.pumpAndSettle();

    expect(http.requests.any((r) => r.method == 'DELETE'), isTrue);
    expect(find.text('Projects'), findsNothing);
    expect(find.text('Folder deleted'), findsOneWidget);
  });
}
