import 'dart:async';

import 'package:file_picker/file_picker.dart';
import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:intl/intl.dart';

import '../../core/errors.dart';
import '../../core/providers.dart';
import '../../shared/widgets.dart';
import '../mailbox/mail_repository.dart';
import '../mailbox/mailbox_providers.dart';
import '../mailbox/models.dart';
import 'formatting_toolbar.dart';
import 'rich_text_controller.dart';

/// What the composer starts with (new, reply, reply-all, forward).
class ComposeDraft {
  const ComposeDraft({
    this.mode = 'new',
    this.to = const [],
    this.cc = const [],
    this.subject = '',
    this.quoted = '',
    this.referenceId,
    this.excludeSelf = false,
  });

  final String mode;
  final List<String> to;
  final List<String> cc;
  final String subject;
  final String quoted;
  final String? referenceId;
  final bool excludeSelf;

  static String _prefix(String p, String s) =>
      s.toLowerCase().startsWith('${p.toLowerCase()}:') ? s : '$p: $s';

  static String _quote(MessageDetail m) {
    final body = (m.text ?? '').trim();
    return '\n\nOn ${DateFormat.yMMMd().add_Hm().format(m.date)}, ${m.from.display} <${m.from.email}> wrote:\n${body.split('\n').map((l) => '> $l').join('\n')}';
  }

  factory ComposeDraft.reply(MessageDetail m) => ComposeDraft(
    mode: 'reply',
    to: [m.from.email],
    subject: _prefix('Re', m.subject),
    quoted: _quote(m),
    referenceId: m.id,
  );

  factory ComposeDraft.replyAll(MessageDetail m) => ComposeDraft(
    mode: 'reply_all',
    to: [m.from.email, ...m.to.map((a) => a.email)],
    cc: m.cc.map((a) => a.email).toList(),
    subject: _prefix('Re', m.subject),
    quoted: _quote(m),
    referenceId: m.id,
    excludeSelf: true,
  );

  factory ComposeDraft.forward(MessageDetail m) => ComposeDraft(
    mode: 'forward',
    subject: _prefix('Fwd', m.subject),
    quoted: _quote(m),
    referenceId: m.id,
  );
}

class ComposeScreen extends ConsumerStatefulWidget {
  const ComposeScreen({super.key, required this.draft});
  final ComposeDraft draft;

  @override
  ConsumerState<ComposeScreen> createState() => _ComposeScreenState();
}

class _ComposeScreenState extends ConsumerState<ComposeScreen> {
  final _form = GlobalKey<FormState>();
  late final _to = TextEditingController();
  late final _cc = TextEditingController();
  late final _bcc = TextEditingController();
  late final _subject = TextEditingController(text: widget.draft.subject);
  late final _body = RichTextController(text: widget.draft.quoted);
  final _attachments = <({String id, String name, String? warning})>[];
  bool _showCc = false;
  bool _sending = false;
  bool _uploading = false;
  bool _includeOriginalAttachments = true;

  /// Sending mailbox. Replies stay on the mailbox that received the message; it is
  /// locked after the first send attempt because the Idempotency-Key covers it.
  String? _fromId;
  bool _attempted = false;

  /// One key per composed message, reused on every retry (FR-SEND-005).
  final String _idempotencyKey = MailRepository.newIdempotencyKey();

  @override
  void initState() {
    super.initState();
    final self = ref.read(currentAccountProvider)?.email.toLowerCase();
    List<String> clean(List<String> l) => {
      for (final e in l.map((e) => e.toLowerCase()))
        if (!(widget.draft.excludeSelf && e == self)) e,
    }.toList();
    _to.text = clean(widget.draft.to).join(', ');
    _cc.text = clean(widget.draft.cc).join(', ');
    _showCc = _cc.text.isNotEmpty;
  }

  @override
  void dispose() {
    for (final c in [_to, _cc, _bcc, _subject, _body]) {
      c.dispose();
    }
    super.dispose();
  }

  static final _email = RegExp(r'^[^\s@<>,;]+@[^\s@<>,;]+\.[^\s@<>,;]+$');

  static List<String> _split(String v) => v
      .split(RegExp(r'[,;\s]+'))
      .map((e) => e.trim())
      .where((e) => e.isNotEmpty)
      .toList();

  String? _validateList(String? v, {bool required = false}) {
    final list = _split(v ?? '');
    if (required &&
        list.isEmpty &&
        _split(_cc.text).isEmpty &&
        _split(_bcc.text).isEmpty) {
      return 'Add at least one recipient';
    }
    final bad = list.where((e) => !_email.hasMatch(e)).toList();
    return bad.isEmpty ? null : 'Not a valid address: ${bad.first}';
  }

  /// Plain-text composer (rich text is a later enhancement): escaped and wrapped into
  /// paragraphs; the server sanitises again and generates the text/plain part.
  Future<void> _pickFiles() async {
    final files = await FilePicker.pickFiles();
    if (files.isEmpty) return;
    setState(() => _uploading = true);
    try {
      for (final f in files) {
        final size = f.lengthSync() ?? await f.length() ?? 0;
        final j = await ref
            .read(mailRepositoryProvider)
            .uploadAttachment(f.name, f.readAsByteStream(), size);
        setState(
          () => _attachments.add((
            id: '${j['id']}',
            name: '${j['filename']}',
            warning: j['warning'] as String?,
          )),
        );
      }
    } catch (e) {
      if (mounted) showFailure(context, e);
    } finally {
      if (mounted) setState(() => _uploading = false);
    }
  }

  MailAccount? _from() {
    final accounts = (ref.read(accountsProvider).value ?? const <MailAccount>[])
        .where((a) => a.usable);
    return accounts.where((a) => a.id == _fromId).firstOrNull ??
        ref.read(currentAccountProvider);
  }

  Future<void> _send() async {
    final account = _from();
    if (account == null) {
      // Never fail silently: there is no mailbox to send from yet.
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(
          content: const Text('Link your company mailbox before sending.'),
          action: SnackBarAction(
            label: 'Link',
            onPressed: () => context.push('/accounts?add=1'),
          ),
        ),
      );
      return;
    }
    if (!_form.currentState!.validate()) return;
    setState(() {
      _sending = true;
      _attempted = true;
    });
    final d = widget.draft;
    try {
      final r = await ref.read(mailRepositoryProvider).send({
        'account_id': account.id,
        'to': _split(_to.text),
        'cc': _split(_cc.text),
        'bcc': _split(_bcc.text),
        'subject': _subject.text.trim(),
        'html': _body.html,
        'text': _body.text,
        'attachment_ids': _attachments.map((a) => a.id).toList(),
        'mode': d.mode,
        if (d.referenceId != null) 'reply_to_message_id': d.referenceId,
        if (d.mode == 'forward')
          'include_original_attachments': _includeOriginalAttachments,
      }, idempotencyKey: _idempotencyKey);
      if (!mounted) return;
      final status = '${r['status']}';
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(
          content: Text(switch (status) {
            'sent' => 'Sent.',
            'unknown' => 'The connection dropped while sending — check Sent before trying again.',
            'failed' => 'The mail server rejected this message.',
            _ => 'Sending…',
          }),
        ),
      );
      if (status != 'failed') context.pop();
    } on AppFailure catch (e) {
      // Same key on retry: the server returns the original message instead of sending twice.
      if (mounted) showFailure(context, e);
    } finally {
      if (mounted) setState(() => _sending = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final account = ref.watch(currentAccountProvider);
    return CallbackShortcuts(
      bindings: {
        const SingleActivator(LogicalKeyboardKey.enter, control: true): _send,
      },
      child: Scaffold(
        appBar: AppBar(
          title: Text(switch (widget.draft.mode) {
            'reply' || 'reply_all' => 'Reply',
            'forward' => 'Forward',
            _ => 'New message',
          }),
          actions: [
            IconButton(
              tooltip: 'Attach',
              icon: const Icon(Icons.attach_file),
              onPressed: _uploading ? null : _pickFiles,
            ),
            IconButton.filled(
              tooltip: 'Send (Ctrl+Enter)',
              icon: _sending
                  ? const SizedBox.square(
                      dimension: 20,
                      child: CircularProgressIndicator(strokeWidth: 2),
                    )
                  : Icon(
                      Icons.send,
                      color: Theme.of(context).colorScheme.onPrimary,
                    ),
              onPressed: _sending || _uploading ? null : _send,
            ),
            const SizedBox(width: 8),
          ],
        ),
        body: Form(
          key: _form,
          child: ListView(
            padding: const EdgeInsets.all(16),
            children: [
              if (account == null)
                Padding(
                  padding: const EdgeInsets.only(bottom: 12),
                  child: Material(
                    color: Theme.of(context).colorScheme.errorContainer,
                    borderRadius: BorderRadius.circular(16),
                    child: ListTile(
                      leading: const Icon(Icons.link_off),
                      title: const Text('No mailbox linked'),
                      subtitle: const Text(
                        'Link your company mailbox to send email.',
                      ),
                      trailing: TextButton(
                        onPressed: () => context.push('/accounts?add=1'),
                        child: const Text('Link'),
                      ),
                    ),
                  ),
                ),
              _FromField(
                value: _fromId ?? account?.id,
                locked: _attempted || widget.draft.mode != 'new',
                onChanged: (id) => setState(() => _fromId = id),
              ),
              const SizedBox(height: 8),
              _RecipientField(
                controller: _to,
                label: 'To',
                validator: (v) => _validateList(v, required: true),
              ),
              if (!_showCc)
                Align(
                  alignment: Alignment.centerLeft,
                  child: TextButton(
                    onPressed: () => setState(() => _showCc = true),
                    child: const Text('Cc / Bcc'),
                  ),
                ),
              if (_showCc) ...[
                const SizedBox(height: 8),
                _RecipientField(
                  controller: _cc,
                  label: 'Cc',
                  validator: _validateList,
                ),
                const SizedBox(height: 8),
                _RecipientField(
                  controller: _bcc,
                  label: 'Bcc',
                  validator: _validateList,
                ),
              ],
              const SizedBox(height: 8),
              TextFormField(
                controller: _subject,
                decoration: const InputDecoration(labelText: 'Subject'),
                maxLength: 998,
                buildCounter: (
                  _, {
                  required currentLength,
                  required isFocused,
                  maxLength,
                }) => null,
              ),
              const SizedBox(height: 8),
              if (_attachments.isNotEmpty || _uploading)
                Wrap(
                  spacing: 8,
                  children: [
                    for (final a in _attachments)
                      InputChip(
                        avatar: Icon(
                          a.warning != null
                              ? Icons.warning_amber_outlined
                              : Icons.attach_file,
                        ),
                        label: Text(a.name),
                        tooltip: a.warning != null
                            ? 'This file type may be unsafe for recipients'
                            : null,
                        onDeleted: () => setState(() => _attachments.remove(a)),
                      ),
                    if (_uploading) const Chip(label: Text('Uploading…')),
                  ],
                ),
              if (widget.draft.mode == 'forward')
                SwitchListTile(
                  value: _includeOriginalAttachments,
                  onChanged: (v) =>
                      setState(() => _includeOriginalAttachments = v),
                  title: const Text('Include original attachments'),
                ),
              FormattingToolbar(controller: _body),
              const SizedBox(height: 8),
              TextFormField(
                controller: _body,
                minLines: 12,
                maxLines: null,
                keyboardType: TextInputType.multiline,
                decoration: const InputDecoration(
                  hintText: 'Write your message',
                  border: InputBorder.none,
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }
}

/// Recipient input with suggestions from the user's own contacts and the company
/// directory (FR-CONT-001) for the address being typed.
class _RecipientField extends ConsumerStatefulWidget {
  const _RecipientField({
    required this.controller,
    required this.label,
    required this.validator,
  });
  final TextEditingController controller;
  final String label;
  final String? Function(String?) validator;

  @override
  ConsumerState<_RecipientField> createState() => _RecipientFieldState();
}

class _RecipientFieldState extends ConsumerState<_RecipientField> {
  List<Address> _suggestions = const [];
  Timer? _debounce;

  @override
  void dispose() {
    _debounce?.cancel();
    super.dispose();
  }

  String get _current =>
      widget.controller.text.split(RegExp(r'[,;]')).last.trim();

  void _onChanged(String _) {
    _debounce?.cancel();
    final q = _current;
    if (q.length < 2) {
      setState(() => _suggestions = const []);
      return;
    }
    _debounce = Timer(const Duration(milliseconds: 250), () async {
      try {
        final s = await ref.read(mailRepositoryProvider).suggest(q);
        if (mounted && _current == q) {
          setState(() => _suggestions = s.take(5).toList());
        }
      } catch (_) {
        // Suggestions are optional.
      }
    });
  }

  void _pick(Address a) {
    final parts = widget.controller.text.split(RegExp(r'[,;]'));
    parts[parts.length - 1] = ' ${a.email}';
    widget.controller.text =
        '${parts.map((p) => p.trim()).where((p) => p.isNotEmpty).join(', ')}, ';
    widget.controller.selection = TextSelection.collapsed(
      offset: widget.controller.text.length,
    );
    setState(() => _suggestions = const []);
  }

  @override
  Widget build(BuildContext context) => Column(
    crossAxisAlignment: CrossAxisAlignment.stretch,
    children: [
      TextFormField(
        controller: widget.controller,
        decoration: InputDecoration(labelText: widget.label),
        keyboardType: TextInputType.emailAddress,
        validator: widget.validator,
        onChanged: _onChanged,
      ),
      for (final a in _suggestions)
        ListTile(
          dense: true,
          leading: const Icon(Icons.person_outline),
          title: Text(a.display),
          subtitle: Text(a.email),
          onTap: () => _pick(a),
        ),
    ],
  );
}

/// Gmail-style "From" picker across the user's linked company mailboxes.
class _FromField extends ConsumerWidget {
  const _FromField({
    required this.value,
    required this.locked,
    required this.onChanged,
  });
  final String? value;
  final bool locked;
  final ValueChanged<String?> onChanged;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final accounts =
        (ref.watch(accountsProvider).value ?? const <MailAccount>[])
            .where((a) => a.usable)
            .toList();
    final current = accounts.where((a) => a.id == value).firstOrNull;
    if (accounts.length <= 1 || locked) {
      return InputDecorator(
        decoration: const InputDecoration(labelText: 'From'),
        child: Text(current?.email ?? accounts.firstOrNull?.email ?? '—'),
      );
    }
    return DropdownButtonFormField<String>(
      initialValue: current?.id,
      decoration: const InputDecoration(labelText: 'From'),
      items: [
        for (final a in accounts)
          DropdownMenuItem(
            value: a.id,
            child: Text(a.email, overflow: TextOverflow.ellipsis),
          ),
      ],
      onChanged: onChanged,
    );
  }
}
