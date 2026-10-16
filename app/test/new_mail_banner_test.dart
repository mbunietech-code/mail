import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:mbuniemail/core/push/push_service.dart';
import 'package:mbuniemail/shared/new_mail_banner.dart';

void main() {
  MailAlert alert() => MailAlert.fromData({
    'account_id': 'acc1',
    'message_id': 'msg1',
    'title': 'Finance Team',
    'body': 'Sent you a new message',
    'sender': 'Finance Team',
    'count': '1',
  })!;

  Future<StreamController<MailAlert>> pump(
    WidgetTester tester,
    List<String> opened,
  ) async {
    final alerts = StreamController<MailAlert>.broadcast();
    addTearDown(alerts.close);
    await tester.pumpWidget(
      MaterialApp(
        builder: (_, child) => NewMailBannerHost(
          alerts: alerts.stream,
          onOpen: opened.add,
          child: child!,
        ),
        home: const Scaffold(body: Text('Inbox')),
      ),
    );
    return alerts;
  }

  testWidgets('new mail drops in as a banner and opens the message on tap', (
    tester,
  ) async {
    final opened = <String>[];
    final alerts = await pump(tester, opened);

    alerts.add(alert());
    await tester.pump();
    await tester.pump(const Duration(milliseconds: 600));
    expect(find.text('Finance Team'), findsOneWidget);
    expect(find.text('Sent you a new message'), findsOneWidget);

    await tester.tap(find.text('Finance Team'));
    await tester.pumpAndSettle();
    expect(opened, ['msg1']);
    expect(find.text('Finance Team'), findsNothing);
  });

  testWidgets('the banner hides itself after a few seconds', (tester) async {
    final alerts = await pump(tester, []);

    alerts.add(alert());
    await tester.pump();
    await tester.pump(const Duration(seconds: 6));
    await tester.pumpAndSettle();
    expect(find.text('Finance Team'), findsNothing);
  });

  test('alerts without a message id are ignored', () {
    expect(MailAlert.fromData({'title': 'x'}), isNull);
  });
}
