import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:mbuniemail/features/compose/formatting_toolbar.dart';
import 'package:mbuniemail/features/compose/rich_text_controller.dart';

/// Rich-text composing: formats, lists and the HTML that is sent.
void main() {
  RichTextController typed(String text) {
    final c = RichTextController();
    for (var i = 1; i <= text.length; i++) {
      c.value = TextEditingValue(
        text: text.substring(0, i),
        selection: TextSelection.collapsed(offset: i),
      );
    }
    return c;
  }

  void select(RichTextController c, int start, int end) =>
      c.selection = TextSelection(baseOffset: start, extentOffset: end);

  test('formatting a selection wraps only that text', () {
    final c = typed('Hello world');
    select(c, 6, 11);
    c.toggle(Mark.bold);
    c.toggle(Mark.underline);

    expect(c.html, '<div>Hello <b><u>world</u></b></div>');
    expect(c.isActive(Mark.bold), isTrue);
    c.toggle(Mark.bold);
    expect(c.html, '<div>Hello <u>world</u></div>');
  });

  test('a format turned on before typing applies to the new text', () {
    final c = typed('Hi ');
    c.toggle(Mark.italic);
    c.value = const TextEditingValue(
      text: 'Hi there',
      selection: TextSelection.collapsed(offset: 8),
    );

    expect(c.html, '<div>Hi <i>there</i></div>');
  });

  test('deleting text keeps the formats on the right characters', () {
    final c = typed('abcdef');
    select(c, 3, 6);
    c.toggle(Mark.bold);
    c.value = const TextEditingValue(
      text: 'abdef',
      selection: TextSelection.collapsed(offset: 2),
    );

    expect(c.html, '<div>ab<b>def</b></div>');
  });

  test('everything is escaped and web addresses become links', () {
    final c = typed('<script>x</script> see https://mt.co.tz/a?b=1&c=2.');

    expect(c.html, contains('&lt;script&gt;x&lt;/script&gt;'));
    expect(c.html, isNot(contains('<script>')));
    expect(
      c.html,
      contains(
        '<a href="https://mt.co.tz/a?b=1&amp;c=2">https://mt.co.tz/a?b=1&amp;c=2</a>.',
      ),
    );
  });

  test('lists: toggling, Enter continues, Enter on an empty item ends', () {
    final c = typed('Milk');
    c.toggleList(ListKind.bullet);
    expect(c.text, '• Milk');

    // Enter after "Milk" starts a new item.
    c.value = const TextEditingValue(
      text: '• Milk\n',
      selection: TextSelection.collapsed(offset: 7),
    );
    expect(c.text, '• Milk\n• ');
    c.value = const TextEditingValue(
      text: '• Milk\n• Bread',
      selection: TextSelection.collapsed(offset: 14),
    );
    expect(c.html, '<ul><li>Milk</li><li>Bread</li></ul>');

    // Enter twice leaves the list.
    c.value = const TextEditingValue(
      text: '• Milk\n• Bread\n',
      selection: TextSelection.collapsed(offset: 15),
    );
    c.value = TextEditingValue(
      text: '${c.text}\n',
      selection: TextSelection.collapsed(offset: c.text.length + 1),
    );
    expect(c.text, '• Milk\n• Bread\n');

    select(c, 0, 14);
    c.toggleList(ListKind.numbered);
    expect(c.text, '1. Milk\n2. Bread\n');
    expect(c.html, '<ol><li>Milk</li><li>Bread</li></ol><br>');
  });

  testWidgets('toolbar buttons show and change the format', (tester) async {
    final c = typed('Report');
    await tester.pumpWidget(
      MaterialApp(
        home: Scaffold(
          body: Column(
            children: [
              FormattingToolbar(controller: c),
              TextField(controller: c),
            ],
          ),
        ),
      ),
    );
    select(c, 0, 6);
    await tester.pump();

    await tester.tap(find.byType(TextField));
    await tester.pump();
    select(c, 0, 6);
    await tester.pump();
    await tester.tap(find.byTooltip('Bold'));
    await tester.pump();
    expect(c.html, '<div><b>Report</b></div>');
    // The field keeps focus (and the keyboard) when a button is tapped.
    expect(
      tester.widget<EditableText>(find.byType(EditableText)).focusNode.hasFocus,
      isTrue,
    );
    expect(
      tester
          .widget<IconButton>(
            find.widgetWithIcon(IconButton, Icons.format_bold),
          )
          .isSelected,
      isTrue,
    );
  });
}
