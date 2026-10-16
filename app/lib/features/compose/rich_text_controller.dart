import 'dart:convert' show HtmlEscape, HtmlEscapeMode;

import 'package:flutter/material.dart';

/// Inline formats, combinable as bit flags.
abstract final class Mark {
  static const bold = 1;
  static const italic = 2;
  static const underline = 4;
  static const strike = 8;
}

enum ListKind { bullet, numbered }

/// A text controller that keeps bold/italic/underline/strike per character and
/// bullet/numbered lines, and turns the result into safe HTML for sending.
///
/// Formats live beside the plain text, so the field stays an ordinary
/// [TextField]: typing, selection and the keyboard behave as usual.
class RichTextController extends TextEditingController {
  RichTextController({super.text}) : _marks = List.filled(text?.length ?? 0, 0);

  static const bulletPrefix = '• ';
  static final _numbered = RegExp(r'^(\d+)\. ');

  List<int> _marks;

  /// Formats applied to what is typed next (from the toolbar or the cursor position).
  int _typing = 0;

  /// Formats of each character, for tests and rendering.
  List<int> get marks => List.unmodifiable(_marks);

  @override
  set value(TextEditingValue newValue) {
    final old = super.value;
    if (old.text != newValue.text) {
      newValue = _continueList(old, newValue);
      _remap(old.text, newValue.text);
    } else if (old.selection != newValue.selection &&
        newValue.selection.isCollapsed) {
      // Moving the cursor picks up the formats of the character before it.
      final at = newValue.selection.baseOffset;
      _typing = at > 0 && at <= _marks.length ? _marks[at - 1] : 0;
    }
    super.value = newValue;
  }

  void _remap(String old, String now) {
    var p = 0;
    while (p < old.length && p < now.length && old[p] == now[p]) {
      p++;
    }
    var s = 0;
    while (s < old.length - p &&
        s < now.length - p &&
        old[old.length - 1 - s] == now[now.length - 1 - s]) {
      s++;
    }
    final inserted = now.length - p - s;
    _marks = [
      ..._marks.sublist(0, p),
      ...List.filled(inserted, _typing),
      ..._marks.sublist(old.length - s),
    ];
  }

  /// Enter on a list line starts the next item; Enter on an empty item ends the list.
  TextEditingValue _continueList(TextEditingValue old, TextEditingValue now) {
    final cursor = now.selection.baseOffset;
    final typedNewline =
        now.text.length == old.text.length + 1 &&
        cursor > 0 &&
        now.text[cursor - 1] == '\n' &&
        now.selection.isCollapsed;
    if (!typedNewline) return now;

    final lineStart = _lineStart(now.text, cursor - 1);
    final line = now.text.substring(lineStart, cursor - 1);
    final prefix = _prefixOf(line);
    if (prefix == null) return now;

    if (line.length == prefix.length) {
      // Empty item: remove its marker and the new line instead.
      final text = now.text.replaceRange(lineStart, cursor, '');
      return TextEditingValue(
        text: text,
        selection: TextSelection.collapsed(offset: lineStart),
      );
    }
    final next = prefix == bulletPrefix
        ? bulletPrefix
        : '${int.parse(_numbered.firstMatch(prefix)!.group(1)!) + 1}. ';
    return TextEditingValue(
      text: now.text.replaceRange(cursor, cursor, next),
      selection: TextSelection.collapsed(offset: cursor + next.length),
    );
  }

  /// Start of the line containing [offset] (a cursor position).
  static int _lineStart(String text, int offset) =>
      offset <= 0 ? 0 : text.lastIndexOf('\n', offset - 1) + 1;

  static String? _prefixOf(String line) {
    if (line.startsWith(bulletPrefix)) return bulletPrefix;
    return _numbered.firstMatch(line)?.group(0);
  }

  // ---- Toolbar ------------------------------------------------------------------

  bool isActive(int mark) {
    final sel = selection;
    if (!sel.isValid || sel.isCollapsed) return _typing & mark != 0;
    final range = _marks.sublist(sel.start, sel.end);
    return range.isNotEmpty && range.every((m) => m & mark != 0);
  }

  void toggle(int mark) {
    final sel = selection;
    if (!sel.isValid || sel.isCollapsed) {
      _typing ^= mark;
      notifyListeners();
      return;
    }
    final on = !isActive(mark);
    for (var i = sel.start; i < sel.end; i++) {
      _marks[i] = on ? _marks[i] | mark : _marks[i] & ~mark;
    }
    notifyListeners();
  }

  void clearFormatting() {
    final sel = selection;
    if (sel.isValid && !sel.isCollapsed) {
      for (var i = sel.start; i < sel.end; i++) {
        _marks[i] = 0;
      }
    }
    _typing = 0;
    notifyListeners();
  }

  /// Lines the selection touches, as (start, end) offsets.
  List<(int, int)> _selectedLines() {
    final sel = selection.isValid
        ? selection
        : TextSelection.collapsed(offset: text.length);
    final lines = <(int, int)>[];
    var start = _lineStart(text, sel.start);
    while (true) {
      var end = text.indexOf('\n', start);
      if (end < 0) end = text.length;
      lines.add((start, end));
      if (end >= sel.end || end >= text.length) break;
      start = end + 1;
    }
    return lines;
  }

  ListKind? get activeList {
    final lines = _selectedLines();
    bool all(bool Function(String) test) =>
        lines.every((l) => test(text.substring(l.$1, l.$2)));
    if (all((l) => l.startsWith(bulletPrefix))) return ListKind.bullet;
    if (all((l) => _numbered.hasMatch(l))) return ListKind.numbered;
    return null;
  }

  /// Turns the selected lines into a list of [kind], or back to plain lines.
  void toggleList(ListKind kind) {
    final remove = activeList == kind;
    final lines = _selectedLines();
    var newText = text;
    var newMarks = [..._marks];
    var shift = 0;
    var number = 1;
    for (final (s0, e0) in lines) {
      final s = s0 + shift;
      final line = newText.substring(s, e0 + shift);
      final old = _prefixOf(line) ?? '';
      final add = remove
          ? ''
          : kind == ListKind.bullet
          ? bulletPrefix
          : '${number++}. ';
      newText = newText.replaceRange(s, s + old.length, add);
      newMarks = [
        ...newMarks.sublist(0, s),
        ...List.filled(add.length, 0),
        ...newMarks.sublist(s + old.length),
      ];
      shift += add.length - old.length;
    }
    _marks = newMarks;
    final end = (selection.isValid ? selection.end : text.length) + shift;
    super.value = TextEditingValue(
      text: newText,
      selection: TextSelection.collapsed(offset: end.clamp(0, newText.length)),
    );
  }

  // ---- Rendering --------------------------------------------------------------

  static TextStyle styleFor(int m, TextStyle? base) {
    final decorations = [
      if (m & Mark.underline != 0) TextDecoration.underline,
      if (m & Mark.strike != 0) TextDecoration.lineThrough,
    ];
    return (base ?? const TextStyle()).copyWith(
      fontWeight: m & Mark.bold != 0 ? FontWeight.w700 : null,
      fontStyle: m & Mark.italic != 0 ? FontStyle.italic : null,
      decoration: decorations.isEmpty
          ? null
          : TextDecoration.combine(decorations),
    );
  }

  @override
  TextSpan buildTextSpan({
    required BuildContext context,
    TextStyle? style,
    required bool withComposing,
  }) {
    if (_marks.length != text.length || _marks.every((m) => m == 0)) {
      return super.buildTextSpan(
        context: context,
        style: style,
        withComposing: withComposing,
      );
    }
    final children = <TextSpan>[];
    var start = 0;
    for (var i = 1; i <= text.length; i++) {
      if (i == text.length || _marks[i] != _marks[start]) {
        children.add(
          TextSpan(
            text: text.substring(start, i),
            style: styleFor(_marks[start], null),
          ),
        );
        start = i;
      }
    }
    return TextSpan(style: style, children: children);
  }

  // ---- Output -----------------------------------------------------------------

  static final _url = RegExp(r'''https?://[^\s<>"']+[^\s<>"'.,;:!?)\]]''');

  static const _escapeText = HtmlEscape(HtmlEscapeMode.element);
  static const _escapeAttr = HtmlEscape(HtmlEscapeMode.attribute);

  /// Escapes [raw], turning web addresses into links.
  static String _linked(String raw) {
    final b = StringBuffer();
    var last = 0;
    for (final u in _url.allMatches(raw)) {
      b
        ..write(_escapeText.convert(raw.substring(last, u.start)))
        ..write(
          '<a href="${_escapeAttr.convert(u[0]!)}">${_escapeText.convert(u[0]!)}</a>',
        );
      last = u.end;
    }
    b.write(_escapeText.convert(raw.substring(last)));
    return b.toString();
  }

  /// Safe HTML: every character is escaped; only our own tags are emitted.
  String get html {
    final out = StringBuffer();
    String? openList;
    var offset = 0;

    String inline(int from, int to) {
      final b = StringBuffer();
      var i = from;
      while (i < to) {
        var j = i + 1;
        while (j < to && _marks[j] == _marks[i]) {
          j++;
        }
        final m = _marks[i];
        var run = _linked(text.substring(i, j));
        if (m & Mark.strike != 0) run = '<s>$run</s>';
        if (m & Mark.underline != 0) run = '<u>$run</u>';
        if (m & Mark.italic != 0) run = '<i>$run</i>';
        if (m & Mark.bold != 0) run = '<b>$run</b>';
        b.write(run);
        i = j;
      }
      return b.toString();
    }

    for (final line in text.split('\n')) {
      final start = offset;
      final end = start + line.length;
      offset = end + 1;
      final prefix = _prefixOf(line);
      final list = prefix == null
          ? null
          : prefix == bulletPrefix
          ? 'ul'
          : 'ol';
      if (openList != null && list != openList) {
        out.write('</$openList>');
        openList = null;
      }
      if (list != null) {
        if (openList == null) {
          out.write('<$list>');
          openList = list;
        }
        out.write('<li>${inline(start + prefix!.length, end)}</li>');
      } else if (line.isEmpty) {
        out.write('<br>');
      } else {
        out.write('<div>${inline(start, end)}</div>');
      }
    }
    if (openList != null) out.write('</$openList>');
    return out.toString();
  }
}
