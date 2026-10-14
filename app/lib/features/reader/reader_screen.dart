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
        padding: const EdgeInsets.all(16),
        children: [
          SelectableText(
            m.subject.isEmpty ? '(no subject)' : m.subject,
            style: Theme.of(context).textTheme.headlineSmall,
          ),
          const SizedBox(height: 8),
          _Actions(message: m, onRemoved: _removed),
          const SizedBox(height: 8),
          _SecurityBanner(security: m.security, from: m.from),
          ListTile(
            contentPadding: EdgeInsets.zero,
            leading: CircleAvatar(
              child: Text(
                m.from.display.isEmpty
                    ? '?'
                    : m.from.display.characters.first.toUpperCase(),
              ),
            ),
            title: Text(m.from.display),
            // The real address is always shown next to the display name (risk R-10).
            subtitle: Text(
              '${m.from.email}\nto ${m.to.map((a) => a.display).join(', ')}${m.cc.isEmpty ? '' : '\ncc ${m.cc.map((a) => a.display).join(', ')}'}',
            ),
            isThreeLine: true,
            trailing: Text(DateFormat.yMMMd().add_Hm().format(m.date)),
          ),
          if (m.remoteImagesBlocked > 0 && !m.remoteImagesAllowed)
            Card(
              child: ListTile(
                leading: const Icon(Icons.image_not_supported_outlined),
                title: Text(
                  '${m.remoteImagesBlocked} remote image(s) blocked to protect your privacy.',
                ),
                trailing: TextButton(
                  onPressed: () => setState(() => _images = true),
                  child: const Text('Load images'),
                ),
              ),
            ),
          const Divider(),
          _Body(message: m),
          if (m.attachments.where((a) => !a.isInline).isNotEmpty) ...[
            const Divider(),
            Wrap(
              spacing: 8,
              runSpacing: 8,
              children: [
                for (final a in m.attachments.where((a) => !a.isInline))
                  _AttachmentChip(attachment: a),
              ],
            ),
          ],
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
          tooltip: 'Reply',
          icon: const Icon(Icons.reply),
          onPressed: () =>
              context.push('/compose', extra: ComposeDraft.reply(message)),
        ),
        IconButton(
          tooltip: 'Reply all',
          icon: const Icon(Icons.reply_all),
          onPressed: () =>
              context.push('/compose', extra: ComposeDraft.replyAll(message)),
        ),
        IconButton(
          tooltip: 'Forward',
          icon: const Icon(Icons.forward),
          onPressed: () =>
              context.push('/compose', extra: ComposeDraft.forward(message)),
        ),
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
    return Card(
      color: danger ? scheme.errorContainer : scheme.secondaryContainer,
      child: Padding(
        padding: const EdgeInsets.all(12),
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
