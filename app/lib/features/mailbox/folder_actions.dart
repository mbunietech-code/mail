import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../core/providers.dart';
import '../../shared/widgets.dart';
import 'mailbox_providers.dart';
import 'models.dart';

/// Create, rename and delete custom folders (FR-MAIL-002). System folders
/// (Inbox, Sent, Trash, ...) are never offered.

Future<void> createFolder(
  BuildContext context,
  WidgetRef ref,
  String accountId,
) async {
  final name = await _askName(context, title: 'New folder', action: 'Create');
  if (name == null || !context.mounted) return;
  await _run(context, ref, accountId, 'Folder “$name” created', () {
    return ref.read(mailRepositoryProvider).createFolder(accountId, name);
  });
}

Future<void> manageFolder(
  BuildContext context,
  WidgetRef ref,
  String accountId,
  MailFolder folder,
) async {
  final choice = await showModalBottomSheet<String>(
    context: context,
    showDragHandle: true,
    builder: (sheet) => SafeArea(
      child: Column(
        mainAxisSize: MainAxisSize.min,
        children: [
          ListTile(
            leading: const Icon(Icons.label_outline),
            title: Text(
              folder.label,
              style: Theme.of(sheet).textTheme.titleMedium,
            ),
          ),
          ListTile(
            leading: const Icon(Icons.drive_file_rename_outline),
            title: const Text('Rename'),
            onTap: () => Navigator.pop(sheet, 'rename'),
          ),
          ListTile(
            leading: Icon(
              Icons.delete_outline,
              color: Theme.of(sheet).colorScheme.error,
            ),
            title: Text(
              'Delete folder',
              style: TextStyle(color: Theme.of(sheet).colorScheme.error),
            ),
            onTap: () => Navigator.pop(sheet, 'delete'),
          ),
        ],
      ),
    ),
  );
  if (!context.mounted) return;

  if (choice == 'rename') {
    final name = await _askName(
      context,
      title: 'Rename folder',
      action: 'Rename',
      initial: folder.label,
    );
    if (name == null || name == folder.label || !context.mounted) return;
    await _run(context, ref, accountId, 'Renamed to “$name”', () {
      return ref.read(mailRepositoryProvider).renameFolder(folder.id, name);
    });
  } else if (choice == 'delete') {
    final ok = await showDialog<bool>(
      context: context,
      builder: (dialog) => AlertDialog(
        icon: const Icon(Icons.delete_outline),
        title: Text('Delete “${folder.label}”?'),
        content: Text(
          folder.total > 0
              ? 'Its ${folder.total} message${folder.total == 1 ? '' : 's'} will be moved to Trash.'
              : 'The folder is empty.',
        ),
        actions: [
          TextButton(
            onPressed: () => Navigator.pop(dialog, false),
            child: const Text('Cancel'),
          ),
          FilledButton(
            onPressed: () => Navigator.pop(dialog, true),
            child: const Text('Delete'),
          ),
        ],
      ),
    );
    if (ok != true || !context.mounted) return;
    if (ref.read(currentFolderProvider)?.id == folder.id) {
      ref.read(selectedFolderIdProvider.notifier).select(null); // back to Inbox
    }
    await _run(context, ref, accountId, 'Folder deleted', () {
      return ref.read(mailRepositoryProvider).deleteFolder(folder.id);
    });
  }
}

Future<void> _run(
  BuildContext context,
  WidgetRef ref,
  String accountId,
  String done,
  Future<void> Function() action,
) async {
  final messenger = ScaffoldMessenger.of(context);
  try {
    await action();
    ref.invalidate(foldersProvider(accountId));
    messenger.showSnackBar(SnackBar(content: Text(done)));
  } catch (e) {
    if (context.mounted) showFailure(context, e);
  }
}

Future<String?> _askName(
  BuildContext context, {
  required String title,
  required String action,
  String initial = '',
}) => showDialog<String>(
  context: context,
  builder: (_) =>
      _FolderNameDialog(title: title, action: action, initial: initial),
);

class _FolderNameDialog extends StatefulWidget {
  const _FolderNameDialog({
    required this.title,
    required this.action,
    required this.initial,
  });
  final String title;
  final String action;
  final String initial;

  @override
  State<_FolderNameDialog> createState() => _FolderNameDialogState();
}

class _FolderNameDialogState extends State<_FolderNameDialog> {
  late final _ctrl = TextEditingController(text: widget.initial)
    ..selection = TextSelection(
      baseOffset: 0,
      extentOffset: widget.initial.length,
    );
  final _form = GlobalKey<FormState>();

  @override
  void dispose() {
    _ctrl.dispose();
    super.dispose();
  }

  void _submit() {
    if (_form.currentState!.validate()) {
      Navigator.pop(context, _ctrl.text.trim());
    }
  }

  @override
  Widget build(BuildContext context) => AlertDialog(
    title: Text(widget.title),
    content: Form(
      key: _form,
      child: TextFormField(
        controller: _ctrl,
        autofocus: true,
        maxLength: 100,
        decoration: const InputDecoration(labelText: 'Folder name'),
        textInputAction: TextInputAction.done,
        onFieldSubmitted: (_) => _submit(),
        validator: (v) {
          final name = (v ?? '').trim();
          if (name.isEmpty) return 'Enter a name';
          if (RegExp(r'[*%"\\/.]').hasMatch(name)) {
            return r'Avoid the characters * % " \ / .';
          }
          return null;
        },
      ),
    ),
    actions: [
      TextButton(
        onPressed: () => Navigator.pop(context),
        child: const Text('Cancel'),
      ),
      FilledButton(onPressed: _submit, child: Text(widget.action)),
    ],
  );
}
