import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:mbuniemail/app.dart';
import 'package:mbuniemail/core/api/api_client.dart';
import 'package:mbuniemail/core/providers.dart';
import 'package:mbuniemail/core/storage/token_store.dart';
import 'package:mbuniemail/features/auth/auth_controller.dart';

import 'fakes.dart';

/// Full-app tests against a fake backend (no network, no real mailboxes).
void main() {
  late FakeAdapter http;
  late MemoryTokenStore store;

  final account = {
    'id': 'A1',
    'email_address': 'developer@mt.co.tz',
    'status': 'active',
    'provider': 'hostinger',
  };
  final inbox = {
    'id': 'F1',
    'name': 'INBOX',
    'role': 'inbox',
    'unread_count': 1,
    'total_count': 2,
  };
  Map<String, dynamic> summary(
    String id,
    String subject, {
    bool seen = false,
  }) => {
    'id': id,
    'account_id': 'A1',
    'folder_id': 'F1',
    'thread_key': 'T$id',
    'from': {'email': 'partner@example.org', 'name': 'Partner'},
    'subject': subject,
    'snippet': 'Preview of $subject',
    'date': '2026-10-14T08:00:00Z',
    'is_seen': seen,
    'is_flagged': false,
    'has_attachments': false,
    'is_external': true,
  };

  void mailRoutes({Map<String, dynamic>? detail}) {
    http.on(
      'GET',
      'me',
      (r) => FakeReply(200, {'email': 'developer@mt.co.tz'}),
    );
    http.on(
      'GET',
      'accounts',
      (r) => FakeReply(200, {
        'data': [account],
      }),
    );
    http.on(
      'GET',
      'accounts/A1/folders',
      (r) => FakeReply(200, {
        'data': [inbox],
      }),
    );
    http.on(
      'GET',
      'folders/F1/messages',
      (r) => FakeReply(200, {
        'data': [
          summary('M1', 'Quarterly plan'),
          summary('M2', 'Lunch', seen: true),
        ],
        'next_cursor': null,
      }),
    );
    http.on('GET', 'sync/status', (r) => FakeReply(200, {'accounts': []}));
    http.on(
      'PATCH',
      'messages/M1',
      (r) => FakeReply(200, summary('M1', 'Quarterly plan', seen: true)),
    );
    if (detail != null) {
      http.on('GET', 'messages/M1', (r) => FakeReply(200, detail));
    }
  }

  Future<void> pumpApp(
    WidgetTester tester, {
    Size size = const Size(400, 800),
  }) async {
    tester.view.physicalSize = size;
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

  setUp(() {
    http = FakeAdapter();
    store = MemoryTokenStore();
  });

  testWidgets('signed out → login; wrong password shows a generic error', (
    tester,
  ) async {
    http.on(
      'POST',
      'auth/login',
      (r) => FakeReply(401, problem('INVALID_CREDENTIALS', 401)),
    );
    await pumpApp(tester);

    expect(find.text('Sign in'), findsOneWidget);
    await tester.enterText(
      find.widgetWithText(TextFormField, 'Work email'),
      'developer@mt.co.tz',
    );
    await tester.enterText(
      find.widgetWithText(TextFormField, 'Password'),
      'wrong-password',
    );
    await tester.tap(find.text('Sign in'));
    await tester.pumpAndSettle();

    expect(find.text('Incorrect email or password.'), findsOneWidget);
    expect(store.value, isNull);
  });

  testWidgets(
    'password + TOTP sign-in reaches the inbox and stores tokens securely',
    (tester) async {
      mailRoutes();
      http.on(
        'POST',
        'auth/login',
        (r) => FakeReply(200, {
          'status': 'mfa_required',
          'mfa_token': 'pending-1',
          'expires_in': 300,
        }),
      );
      http.on('POST', 'auth/mfa/verify', (r) {
        expect(r.headers['Authorization'], 'Bearer pending-1');
        expect(r.data, {'code': '123456'});
        return FakeReply(200, {
          'status': 'authenticated',
          'access_token': 'a',
          'refresh_token': 'r',
          'session_id': 's',
          'user': {'email': 'developer@mt.co.tz'},
        });
      });
      await pumpApp(tester);

      await tester.enterText(
        find.widgetWithText(TextFormField, 'Work email'),
        'developer@mt.co.tz',
      );
      await tester.enterText(
        find.widgetWithText(TextFormField, 'Password'),
        'Correct-Horse-Battery-9',
      );
      await tester.tap(find.text('Sign in'));
      await tester.pumpAndSettle();
      expect(find.text('Two-step verification'), findsOneWidget);

      await tester.enterText(find.byType(TextField), '123 456');
      await tester.tap(find.text('Verify'));
      await tester.pumpAndSettle();

      expect(find.text('Quarterly plan'), findsOneWidget);
      expect(store.value?.accessToken, 'a');
      final login = http.requests.firstWhere(
        (r) => r.path.endsWith('auth/login'),
      );
      expect(
        (login.data as Map)['device'],
        containsPair('platform', isA<String>()),
      );
    },
  );

  testWidgets(
    'policy-forced MFA enrolment shows the key, then recovery codes once',
    (tester) async {
      mailRoutes();
      http.on(
        'POST',
        'auth/login',
        (r) => FakeReply(200, {
          'status': 'mfa_enrollment_required',
          'mfa_token': 'enrol-1',
        }),
      );
      http.on(
        'POST',
        'auth/mfa/enroll',
        (r) => FakeReply(200, {
          'secret': 'JBSWY3DPEHPK3PXP',
          'otpauth_uri': 'otpauth://totp/x',
        }),
      );
      http.on(
        'POST',
        'auth/mfa/confirm',
        (r) => FakeReply(200, {
          'recovery_codes': ['aaaaa-bbbbb', 'ccccc-ddddd'],
          'tokens': {
            'status': 'authenticated',
            'access_token': 'a',
            'refresh_token': 'r',
            'session_id': 's',
            'user': {'email': 'developer@mt.co.tz'},
          },
        }),
      );
      await pumpApp(tester);

      await tester.enterText(
        find.widgetWithText(TextFormField, 'Work email'),
        'developer@mt.co.tz',
      );
      await tester.enterText(
        find.widgetWithText(TextFormField, 'Password'),
        'x',
      );
      await tester.tap(find.text('Sign in'));
      await tester.pumpAndSettle();
      await tester.tap(find.text('Start setup'));
      await tester.pumpAndSettle();
      expect(find.text('JBSW Y3DP EHPK 3PXP'), findsOneWidget);

      await tester.enterText(find.byType(TextField), '654321');
      await tester.tap(find.text('Confirm'));
      await tester.pumpAndSettle();
      expect(find.textContaining('aaaaa-bbbbb'), findsOneWidget);

      await tester.tap(find.text('I have saved them'));
      await tester.pumpAndSettle();
      expect(find.text('Quarterly plan'), findsOneWidget);
    },
  );

  testWidgets('sign-in only accepts company domains, shown as chips', (
    tester,
  ) async {
    http.on(
      'GET',
      'auth/domains',
      (r) => FakeReply(200, {
        'domains': ['mt.co.tz', 'mbuniehub.com', 'mbunir.tech'],
      }),
    );
    http.on('POST', 'auth/login', (r) => FakeReply(500));
    await pumpApp(tester);

    expect(find.text('@mbuniehub.com'), findsOneWidget);
    await tester.enterText(
      find.widgetWithText(TextFormField, 'Work email'),
      'someone@gmail.com',
    );
    await tester.enterText(find.widgetWithText(TextFormField, 'Password'), 'x');
    await tester.tap(find.text('Sign in'));
    await tester.pumpAndSettle();
    expect(find.textContaining('Use your company email'), findsOneWidget);
    expect(http.requests.where((r) => r.path.endsWith('auth/login')), isEmpty);

    await tester.enterText(
      find.widgetWithText(TextFormField, 'Work email'),
      'someone',
    );
    await tester.tap(find.text('@mbunir.tech'));
    await tester.pumpAndSettle();
    expect(find.text('someone@mbunir.tech'), findsOneWidget);
  });

  group('signed in', () {
    setUp(() => store.value = session());

    testWidgets('phone layout: list with unread marker, drawer for folders', (
      tester,
    ) async {
      mailRoutes();
      await pumpApp(tester);

      expect(find.text('Quarterly plan'), findsOneWidget);
      expect(find.text('Lunch'), findsOneWidget);
      expect(
        find.bySemanticsLabel(
          RegExp(r'^Unread\. From Partner\. Quarterly plan'),
        ),
        findsOneWidget,
      );
      expect(find.byTooltip('Open navigation menu'), findsOneWidget);
      expect(find.text('Select a message to read'), findsNothing);
    });

    testWidgets('desktop layout: sidebar, list and reader side by side', (
      tester,
    ) async {
      mailRoutes();
      await pumpApp(tester, size: const Size(1400, 900));

      expect(find.text('Inbox'), findsWidgets);
      expect(find.text('Select a message to read'), findsOneWidget);
      expect(find.byTooltip('Open navigation menu'), findsNothing);
    });

    testWidgets(
      'reader: remote images blocked, phishing banner, links need confirmation',
      (tester) async {
        mailRoutes(
          detail: {
            'id': 'M1',
            'thread_key': 'TM1',
            'subject': 'Invoice overdue',
            'headers': {
              'from': {'email': 'ceo@mt-co.tz', 'name': 'CEO'},
              'to': [
                {'email': 'developer@mt.co.tz'},
              ],
              'cc': [],
              'date': '2026-10-14T08:00:00Z',
            },
            'body': {
              'html': '<p>Pay now: <a href="https://evil.example/pay">portal</a></p><img src="https://tracker.example/p.gif">',
              'text': 'Pay now',
              'remote_images_blocked': 1,
              'remote_images_allowed': false,
              'inline_images': {},
            },
            'attachments': [],
            'flags': {'is_flagged': false},
            'security': {
              'external_sender': true,
              'lookalike_domain': true,
              'display_name_spoof': false,
              'reply_to_differs': false,
              'spf': 'fail',
              'dmarc': 'fail',
            },
          },
        );
        await pumpApp(tester, size: const Size(1400, 900));

        await tester.tap(find.text('Quarterly plan'));
        await tester.pumpAndSettle();

        expect(find.text('Be careful with this message'), findsOneWidget);
        expect(find.textContaining('looks like MbunieTech'), findsOneWidget);
        expect(find.textContaining('remote image(s) blocked'), findsOneWidget);
        expect(
          find.textContaining('ceo@mt-co.tz'),
          findsWidgets,
          reason: 'real address shown, not only the display name',
        );
        expect(
          http.requests.where((r) => r.uri.host == 'tracker.example'),
          isEmpty,
          reason: 'nothing is fetched from the sender',
        );

        await tester.tapOnText(find.textRange.ofSubstring('portal'));
        await tester.pumpAndSettle();
        expect(find.text('Open this link?'), findsOneWidget);
        expect(find.text('evil.example'), findsOneWidget);
        await tester.tap(find.text('Cancel'));
        await tester.pumpAndSettle();
      },
    );

    testWidgets(
      'compose: invalid address blocked; retry after failure reuses the Idempotency-Key',
      (tester) async {
        mailRoutes();
        var attempts = 0;
        final keys = <String>[];
        http.on('POST', 'outbox/send', (r) {
          keys.add('${r.headers['Idempotency-Key']}');
          attempts++;
          return attempts == 1
              ? FakeReply(503, problem('PROVIDER_THROTTLED', 503))
              : FakeReply(202, {'id': 'O1', 'status': 'queued'});
        });
        await pumpApp(tester);

        await tester.tap(find.text('Compose'));
        await tester.pumpAndSettle();
        await tester.enterText(
          find.widgetWithText(TextFormField, 'To'),
          'not-an-address',
        );
        await tester.tap(find.byTooltip('Send (Ctrl+Enter)'));
        await tester.pumpAndSettle();
        expect(find.textContaining('Not a valid address'), findsOneWidget);
        expect(attempts, 0);

        await tester.enterText(
          find.widgetWithText(TextFormField, 'To'),
          'partner@example.org',
        );
        await tester.enterText(
          find.widgetWithText(TextFormField, 'Subject'),
          'Hello',
        );
        await tester.tap(find.byTooltip('Send (Ctrl+Enter)'));
        await tester.pumpAndSettle();
        await tester.tap(find.byTooltip('Send (Ctrl+Enter)'));
        await tester.pumpAndSettle();

        expect(attempts, 2);
        expect(
          keys.toSet(),
          hasLength(1),
          reason: 'the same message must keep the same key across retries',
        );
        expect(keys.first, startsWith('send-'));
      },
    );

    testWidgets('sign out wipes the stored session', (tester) async {
      mailRoutes();
      http.on('POST', 'auth/logout', (r) => FakeReply(204));
      http.on('GET', 'auth/sessions', (r) => FakeReply(200, {'data': []}));
      http.on(
        'GET',
        'me/preferences',
        (r) => FakeReply(200, {
          'remote_images': 'ask',
          'show_subject_in_notifications': false,
        }),
      );
      await pumpApp(tester);

      await tester.tap(find.byTooltip('Settings'));
      await tester.pumpAndSettle();
      await tester.scrollUntilVisible(
        find.text('Sign out of MbunieMail on this device'),
        200,
      );
      await tester.ensureVisible(
        find.text('Sign out of MbunieMail on this device'),
      );
      await tester.pumpAndSettle();
      await tester.tap(find.text('Sign out of MbunieMail on this device'));
      await tester.pumpAndSettle();

      expect(store.value, isNull);
      expect(find.text('Sign in'), findsOneWidget);
    });

    testWidgets(
      'account switcher: switch mailbox like Gmail; removed domains are skipped',
      (tester) async {
        mailRoutes();
        final hub = {
          'id': 'A2',
          'email_address': 'amina@mbuniehub.com',
          'status': 'active',
          'provider': 'hostinger',
        };
        final gone = {
          'id': 'A3',
          'email_address': 'old@retired.example',
          'status': 'domain_not_allowed',
          'provider': 'hostinger',
        };
        http.on(
          'GET',
          'accounts',
          (r) => FakeReply(200, {
            'data': [gone, account, hub],
          }),
        );
        http.on(
          'GET',
          'accounts/A2/folders',
          (r) => FakeReply(200, {
            'data': [
              {...inbox, 'id': 'F2', 'unread_count': 4},
            ],
          }),
        );
        http.on(
          'GET',
          'folders/F2/messages',
          (r) => FakeReply(200, {
            'data': [
              {
                ...summary('M9', 'Hub launch plan'),
                'account_id': 'A2',
                'folder_id': 'F2',
              },
            ],
            'next_cursor': null,
          }),
        );
        await pumpApp(tester);

        // The mailbox on a removed domain is never the one in use.
        expect(find.text('Quarterly plan'), findsOneWidget);

        await tester.tap(find.byTooltip('Switch account'));
        await tester.pumpAndSettle();
        expect(find.text('developer@mt.co.tz'), findsWidgets);
        expect(find.text('Domain no longer allowed'), findsOneWidget);
        expect(find.text('Add another account'), findsOneWidget);

        await tester.tap(find.text('amina@mbuniehub.com'));
        await tester.pumpAndSettle();
        expect(find.text('Hub launch plan'), findsOneWidget);
        expect(find.text('Quarterly plan'), findsNothing);

        // Compose sends from the mailbox in use, and can pick another one.
        await tester.tap(find.text('Compose'));
        await tester.pumpAndSettle();
        expect(find.text('amina@mbuniehub.com'), findsOneWidget);
        await tester.tap(find.text('amina@mbuniehub.com'));
        await tester.pumpAndSettle();
        expect(find.text('developer@mt.co.tz'), findsWidgets);
        expect(find.text('old@retired.example'), findsNothing);
      },
    );
    testWidgets(
      'compose without a linked mailbox explains instead of failing silently',
      (tester) async {
        mailRoutes();
        http.on('GET', 'accounts', (r) => FakeReply(200, {'data': []}));
        var sent = 0;
        http.on('POST', 'outbox/send', (r) {
          sent++;
          return FakeReply(202, {'id': 'O1', 'status': 'queued'});
        });
        await pumpApp(tester);

        await tester.tap(find.text('Compose'));
        await tester.pumpAndSettle();
        expect(find.text('No mailbox linked'), findsOneWidget);
        await tester.enterText(
          find.widgetWithText(TextFormField, 'To'),
          'partner@example.org',
        );
        await tester.tap(find.byTooltip('Send (Ctrl+Enter)'));
        await tester.pump();
        expect(
          find.text('Link your company mailbox before sending.'),
          findsOneWidget,
        );
        expect(sent, 0);
      },
    );
  });
}
