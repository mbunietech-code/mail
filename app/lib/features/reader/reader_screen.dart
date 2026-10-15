import 'dart:convert';
import 'dart:typed_data';

import 'package:file_picker/file_picker.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_widget_from_html_core/flutter_widget_from_html_core.dart';
import 'package:go_router/go_router.dart';
import 'package:intl/intl.dart';
import 'package:url_launcher/url_launcher.dart';

import '../../core/providers.dart';
import '../../shared/avatar.dart';
import '../../shared/widgets.dart';
import '../compose/compose_screen.dart';
import '../mailbox/mailbox_providers.dart';
import '../mailbox/models.dart';

final messageDetailProvider =
    FutureProvider.family<MessageDetail, ({String id, bool images})>((
      ref,
      key,
    ) async {
      ref.watch(sessionKeyProvider);
      return ref
          .watch(mailRepositoryProvider)
          .message(key.id, allowImages: key.images);
    });

/// Full-screen reader (narrow layouts).
class ReaderScreen extends StatelessWidget {
  const ReaderScreen({super.key, required this.messageId});
  final String messageId;

  @override
  Widget build(BuildContext context) => Scaffold(
    appBar: AppBar(),
    body: ReaderView(messageId: messageId, popOnRemove: true),
  );
}

/// Sender block. The real address is always shown next to the display name (risk R-10).
class _SenderHeader extends StatefulWidget {
  const _SenderHeader({required this.message});
  final MessageDetail message;

  @override
  State<_SenderHeader> createState() => _SenderHeaderState();
}

class _SenderHeaderState extends State<_SenderHeader> {
  bool _details = false;

  @override
  Widget build(BuildContext context) {
    final m = widget.message;
    final theme = Theme.of(context);
    final muted = theme.colorScheme.onSurfaceVariant;
    final local = m.date.toLocal();
    final now = DateTime.now();
    final when =
        local.year == now.year &&
            local.month == now.month &&
            local.day == now.day
        ? DateFormat.Hm().format(local)
        : DateFormat.MMMd().add_Hm().format(local);
    final recipients = [...m.to, ...m.cc].map((a) => a.display).join(', ');
    return Row(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        LetterAvatar(name: m.from.name ?? '', email: m.from.email),
        const SizedBox(width: 12),
        Expanded(
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Row(
                children: [
                  Flexible(
                    child: Text(
                      m.from.display,
                      maxLines: 1,
                      overflow: TextOverflow.ellipsis,
                      style: theme.textTheme.titleSmall?.copyWith(
                        fontWeight: FontWeight.w700,
                      ),
                    ),
                  ),
                  const SizedBox(width: 8),
                  Text(
                    when,
                    style: theme.textTheme.labelMedium?.copyWith(color: muted),
                  ),
                ],
              ),
              SelectableText(
                m.from.email,
                style: theme.textTheme.bodySmall?.copyWith(color: muted),
              ),
              InkWell(
                borderRadius: BorderRadius.circular(8),
                onTap: () => setState(() => _details = !_details),
                child: Row(
                  mainAxisSize: MainAxisSize.min,
                  children: [
                    Flexible(
                      child: Text(
                        'to $recipients',
                        maxLines: 1,
                        overflow: TextOverflow.ellipsis,
                        style: theme.textTheme.bodySmall?.copyWith(
                          color: muted,
                        ),
                      ),
                    ),
                    AnimatedRotation(
                      turns: _details ? 0.5 : 0,
                      duration: const Duration(milliseconds: 200),
                      child: Icon(Icons.expand_more, size: 18, color: muted),
                    ),
                  ],
                ),
              ),
              AnimatedSize(
                duration: const Duration(milliseconds: 220),
                curve: Curves.easeOutCubic,
                child: !_details
                    ? const SizedBox(width: double.infinity)
                    : Container(
                        width: double.infinity,
                        margin: const EdgeInsets.only(top: 8),
                        padding: const EdgeInsets.all(12),
                        decoration: BoxDecoration(
                          border: Border.all(
                            color: theme.colorScheme.outlineVariant,
                          ),
                          borderRadius: BorderRadius.circular(12),
                        ),
                        child: SelectableText(
                          [
                            'From: ${m.from.display} <${m.from.email}>',
                            'To: ${m.to.map((a) => a.email).join(', ')}',
                            if (m.cc.isNotEmpty)
                              'Cc: ${m.cc.map((a) => a.email).join(', ')}',
                            'Date: ${DateFormat.yMMMEd().add_Hm().format(local)}',
                          ].join('\n'),
                          style: theme.textTheme.bodySmall,
                        ),
                      ),
              ),
            ],
          ),
        ),
      ],
    );
  }
}

class ReaderView extends ConsumerStatefulWidget {
  const ReaderView({
    super.key,
    required this.messageId,
    this.popOnRemove = false,
  });
  final String messageId;
  final bool popOnRemove;

  @override
  ConsumerState<ReaderView> createState() => _ReaderViewState();
}

class _ReaderViewState extends ConsumerState<ReaderView> {
  bool _images = false;

  @override
  Widget build(BuildContext context) {
    final detail = ref.watch(
      messageDetailProvider((id: widget.messageId, images: _images)),
    );
    return detail.when(
      loading: () => const Center(
        child: CircularProgressIndicator(semanticsLabel: 'Loading message'),
      ),
      error: (e, _) => ErrorRetry(
        error: e,
        onRetry: () => ref.invalidate(
          messageDetailProvider((id: widget.messageId, images: _images)),
        ),
      ),
      data: (m) => ListView(
        padding: const EdgeInsets.fromLTRB(20, 8, 20, 32),
        children: [
          Align(
            alignment: Alignment.centerRight,
            child: _Actions(message: m, onRemoved: _removed),
          ),
          SelectableText(
            m.subject.isEmpty ? '(no subject)' : m.subject,
            style: Theme.of(context).textTheme.headlineSmall
                ?.copyWith(fontWeight: FontWeight.w500),
          ),
          const SizedBox(height: 16),
          _SecurityBanner(security: m.security, from: m.from),
          _SenderHeader(message: m),
          if (m.remoteImagesBlocked > 0 && !m.remoteImagesAllowed)
            Padding(
              padding: const EdgeInsets.only(top: 12),
              child: Container(
                padding: const EdgeInsets.fromLTRB(16, 8, 8, 8),
                decoration: BoxDecoration(
                  color: Theme.of(context).colorScheme.surfaceContainerHigh,
                  borderRadius: BorderRadius.circular(16),
                ),
                child: Row(
                  children: [
                    const Icon(Icons.image_not_supported_outlined, size: 20),
                    const SizedBox(width: 12),
                    Expanded(
                      child: Text(
                        '${m.remoteImagesBlocked} remote image(s) blocked to protect your privacy.',
                      ),
                    ),
                    TextButton(
                      onPressed: () => setState(() => _images = true),
                      child: const Text('Load images'),
                    ),
                  ],
                ),
              ),
            ),
          const SizedBox(height: 20),
          _Body(message: m),
          if (m.attachments.where((a) => !a.isInline).isNotEmpty) ...[
            const SizedBox(height: 20),
            Text(
              '${m.attachments.where((a) => !a.isInline).length} attachment(s)',
              style: Theme.of(context).textTheme.labelLarge,
            ),
            const SizedBox(height: 8),
            Wrap(
              spacing: 8,
              runSpacing: 8,
              children: [
                for (final a in m.attachments.where((a) => !a.isInline))
                  _AttachmentChip(attachment: a),
              ],
            ),
          ],
          const SizedBox(height: 28),
          Wrap(
            spacing: 10,
            runSpacing: 10,
            children: [
              OutlinedButton.icon(
                onPressed: () =>
                    context.push('/compose', extra: ComposeDraft.reply(m)),
                icon: const Icon(Icons.reply),
                label: const Text('Reply'),
              ),
              if (m.to.length + m.cc.length > 1)
                OutlinedButton.icon(
                  onPressed: () =>
                      context.push('/compose', extra: ComposeDraft.replyAll(m)),
                  icon: const Icon(Icons.reply_all),
                  label: const Text('Reply all'),
                ),
              OutlinedButton.icon(
                onPressed: () =>
                    context.push('/compose', extra: ComposeDraft.forward(m)),
                icon: const Icon(Icons.forward),
                label: const Text('Forward'),
              ),
            ],
          ),
        ],
      ),
    );
  }

  void _removed() {
    final folder = ref.read(currentFolderProvider);
    if (folder != null) {
      ref
          .read(messageListProvider(folder.id).notifier)
          .removeLocally(widget.messageId);
    }
    ref.read(selectedMessageIdProvider.notifier).select(null);
    if (widget.popOnRemove && context.mounted) context.pop();
  }
}

class _Actions extends ConsumerWidget {
  const _Actions({required this.message, required this.onRemoved});
  final MessageDetail message;
  final VoidCallback onRemoved;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final repo = ref.read(mailRepositoryProvider);
    Future<void> run(Future<void> Function() f, {bool removes = false}) async {
      try {
        await f();
        if (removes) onRemoved();
      } catch (e) {
        if (context.mounted) showFailure(context, e);
      }
    }

    return Wrap(
      spacing: 4,
      children: [
        IconButton(
          tooltip: 'Mark unread',
          icon: const Icon(Icons.mark_email_unread_outlined),
          onPressed: () =>
              run(() => repo.setFlags(message.id, seen: false), removes: false),
        ),
        IconButton(
          tooltip: 'Report spam',
          icon: const Icon(Icons.report_gmailerrorred_outlined),
          onPressed: () =>
              run(() => repo.markSpam(message.id, true), removes: true),
        ),
        if (ref.watch(currentFolderProvider)?.role == 'trash')
          // In Trash, delete is permanent (like Gmail), so confirm first.
          IconButton(
            tooltip: 'Delete forever',
            icon: const Icon(Icons.delete_forever_outlined),
            onPressed: () async {
              final ok = await showDialog<bool>(
                context: context,
                builder: (context) => AlertDialog(
                  title: const Text('Delete forever?'),
                  content: const Text('This can’t be undone.'),
                  actions: [
                    TextButton(
                      onPressed: () => Navigator.pop(context, false),
                      child: const Text('Cancel'),
                    ),
                    FilledButton(
                      onPressed: () => Navigator.pop(context, true),
                      child: const Text('Delete forever'),
                    ),
                  ],
                ),
              );
              if (ok == true) {
                await run(() => repo.trash(message.id), removes: true);
              }
            },
          )
        else
          IconButton(
            tooltip: 'Delete',
            icon: const Icon(Icons.delete_outline),
            onPressed: () => run(() => repo.trash(message.id), removes: true),
          ),
      ],
    );
  }
}

class _SecurityBanner extends StatelessWidget {
  const _SecurityBanner({required this.security, required this.from});
  final SecurityInfo security;
  final Address from;

  @override
  Widget build(BuildContext context) {
    final scheme = Theme.of(context).colorScheme;
    final warnings = <String>[
      if (security.lookalikeDomain)
        'The sender’s domain looks like MbuniTech’s but is different (${from.email.split('@').last}).',
      if (security.displayNameSpoof) 'The sender’s name contains a company address, but the message is from outside.',
      if (security.authFailed) 'This message failed sender authentication (SPF/DMARC). It may be forged.',
      if (security.replyToDiffers)
        'Replies will go to a different address than the sender.',
    ];
    if (warnings.isEmpty && !security.externalSender) {
      return const SizedBox.shrink();
    }
    final danger = security.suspicious;
    return Container(
      margin: const EdgeInsets.only(bottom: 16),
      decoration: BoxDecoration(
        color: danger ? scheme.errorContainer : scheme.secondaryContainer,
        borderRadius: BorderRadius.circular(16),
      ),
      child: Padding(
        padding: const EdgeInsets.all(14),
        child: Row(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Icon(
              danger ? Icons.gpp_bad_outlined : Icons.public,
              color: danger
                  ? scheme.onErrorContainer
                  : scheme.onSecondaryContainer,
            ),
            const SizedBox(width: 12),
            Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(
                    danger ? 'Be careful with this message' : 'External sender',
                    style: const TextStyle(fontWeight: FontWeight.w700),
                  ),
                  if (security.externalSender && warnings.isEmpty)
                    const Text(
                      'This message came from outside MbuniTech. Don’t open unexpected attachments or links.',
                    ),
                  for (final w in warnings) Text('• $w'),
                ],
              ),
            ),
          ],
        ),
      ),
    );
  }
}

/// Renders server-sanitised HTML with native widgets: no JavaScript engine, no WebView,
/// and no network access except through [_ApiImage] (SDD ADR-007, risk R-08/R-09).
class _Body extends ConsumerWidget {
  const _Body({required this.message});
  final MessageDetail message;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final html = message.html;
    if (html == null || html.trim().isEmpty) {
      return SelectableText(message.text ?? '');
    }
    return HtmlWidget(
      html,
      renderMode: RenderMode.column,
      customWidgetBuilder: (element) {
        if (element.localName != 'img') return null;
        final src = element.attributes['src'] ?? '';
        if (src.startsWith('cid:')) {
          final id = message.inlineImages[src.substring(4)];
          return id == null
              ? const SizedBox.shrink()
              : _ApiImage(
                  loader: () => ref.read(mailRepositoryProvider).attachment(id),
                );
        }
        if (src.startsWith('data:image/')) {
          final comma = src.indexOf(',');
          try {
            return Image.memory(
              base64Decode(src.substring(comma + 1)),
              semanticLabel: element.attributes['alt'],
            );
          } catch (_) {
            return const SizedBox.shrink();
          }
        }
        final uri = Uri.tryParse(src);
        if (uri != null && message.remoteImagesAllowed) {
          return _ApiImage(
            loader: () => ref.read(apiClientProvider).getBytesFromApiUrl(uri),
          ); // proxy only
        }
        return const SizedBox.shrink(); // anything else is never fetched
      },
      onTapUrl: (url) => _confirmAndOpen(context, url),
    );
  }

  /// Shows the real destination before leaving the app (phishing defence).
  static Future<bool> _confirmAndOpen(BuildContext context, String url) async {
    final uri = Uri.tryParse(url);
    if (uri == null ||
        !const {'http', 'https', 'mailto'}.contains(uri.scheme)) {
      return true;
    }
    final ok = await showModalBottomSheet<bool>(
      context: context,
      builder: (context) => SafeArea(
        child: Padding(
          padding: const EdgeInsets.all(16),
          child: Column(
            mainAxisSize: MainAxisSize.min,
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              Text(
                'Open this link?',
                style: Theme.of(context).textTheme.titleMedium,
              ),
              const SizedBox(height: 8),
              Text(
                uri.host.isEmpty ? url : uri.host,
                style: const TextStyle(fontWeight: FontWeight.w700),
              ),
              SelectableText(url, maxLines: 4),
              const SizedBox(height: 12),
              const Text(
                'Only continue if you trust where it goes. Never enter your password on a page opened from email.',
              ),
              const SizedBox(height: 12),
              FilledButton(
                onPressed: () => Navigator.pop(context, true),
                child: const Text('Open'),
              ),
              TextButton(
                onPressed: () => Navigator.pop(context, false),
                child: const Text('Cancel'),
              ),
            ],
          ),
        ),
      ),
    );
    if (ok == true) await launchUrl(uri, mode: LaunchMode.externalApplication);
    return true;
  }
}

class _ApiImage extends StatefulWidget {
  const _ApiImage({required this.loader});
  final Future<Uint8List> Function() loader;
  @override
  State<_ApiImage> createState() => _ApiImageState();
}

class _ApiImageState extends State<_ApiImage> {
  late final Future<Uint8List> _bytes = widget.loader();

  @override
  Widget build(BuildContext context) => FutureBuilder<Uint8List>(
    future: _bytes,
    builder: (context, s) => s.hasData
        ? Image.memory(
            s.data!,
            errorBuilder: (_, _, _) => const Icon(Icons.broken_image_outlined),
          )
        : const SizedBox(height: 24),
  );
}

class _AttachmentChip extends ConsumerWidget {
  const _AttachmentChip({required this.attachment});
  final AttachmentInfo attachment;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final a = attachment;
    final risky = a.warning != null;
    return ActionChip(
      avatar: Icon(
        risky ? Icons.warning_amber_outlined : Icons.attach_file,
        color: risky ? Theme.of(context).colorScheme.error : null,
      ),
      label: Text('${a.filename} · ${_size(a.size)}'),
      tooltip: risky ? 'Warning: ${_warning(a.warning!)}' : 'Save attachment',
      onPressed: () async {
        if (risky) {
          final go = await showDialog<bool>(
            context: context,
            builder: (c) => AlertDialog(
              title: const Text('Risky attachment'),
              content: Text(
                '${_warning(a.warning!)} Only save it if you were expecting it from this sender. MbunieMail never opens attachments automatically.',
              ),
              actions: [
                TextButton(
                  onPressed: () => Navigator.pop(c, false),
                  child: const Text('Cancel'),
                ),
                FilledButton(
                  onPressed: () => Navigator.pop(c, true),
                  child: const Text('Save anyway'),
                ),
              ],
            ),
          );
          if (go != true) return;
        }
        try {
          final bytes = await ref.read(mailRepositoryProvider).attachment(a.id);
          final saved = await FilePicker.saveFile(
            fileName: a.filename,
            bytes: bytes,
            mimeType: 'application/octet-stream',
          );
          if (context.mounted && saved != null) {
            ScaffoldMessenger.of(context)
                .showSnackBar(SnackBar(content: Text('Saved ${a.filename}')));
          }
        } catch (e) {
          if (context.mounted) showFailure(context, e);
        }
      },
    );
  }

  static String _size(int b) => b < 1024
      ? '$b B'
      : (b < 1048576
            ? '${(b / 1024).toStringAsFixed(0)} KB'
            : '${(b / 1048576).toStringAsFixed(1)} MB');

  static String _warning(String code) => switch (code) {
    'EXECUTABLE_FILE' =>
      'This is a program or script file and could harm your computer.',
    'TYPE_MISMATCH' => 'The file’s content does not match its name.',
    _ => 'This file may be unsafe.',
  };
}
