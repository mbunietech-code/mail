/// Plain data classes mirroring docs/openapi.yaml. Parsing is defensive: unknown or
/// missing fields fall back to safe defaults rather than crashing the UI.
class Address {
  const Address(this.email, [this.name]);
  final String email;
  final String? name;

  String get display =>
      (name != null && name!.trim().isNotEmpty) ? name! : email;

  factory Address.fromJson(Map<String, dynamic> j) =>
      Address('${j['email'] ?? ''}', j['name'] as String?);
}

class MailAccount {
  const MailAccount({
    required this.id,
    required this.email,
    required this.status,
    this.displayName,
    this.provider = 'hostinger',
    this.lastErrorCode,
    this.lastSyncedAt,
  });
  final String id;
  final String email;
  final String status;
  final String? displayName;
  final String provider;
  final String? lastErrorCode;
  final DateTime? lastSyncedAt;

  bool get needsAttention => const {
    'auth_failed',
    'provider_unreachable',
    'throttled',
    'disabled',
  }.contains(status);

  factory MailAccount.fromJson(Map<String, dynamic> j) => MailAccount(
    id: '${j['id']}',
    email: '${j['email_address']}',
    status: '${j['status'] ?? 'pending'}',
    displayName: j['display_name'] as String?,
    provider: '${j['provider'] ?? 'hostinger'}',
    lastErrorCode: j['last_error_code'] as String?,
    lastSyncedAt: DateTime.tryParse('${j['last_synced_at'] ?? ''}'),
  );
}

class MailFolder {
  const MailFolder({
    required this.id,
    required this.name,
    required this.role,
    this.unread = 0,
    this.total = 0,
  });
  final String id;
  final String name;
  final String role;
  final int unread;
  final int total;

  String get label => switch (role) {
    'inbox' => 'Inbox',
    'sent' => 'Sent',
    'drafts' => 'Drafts',
    'archive' => 'Archive',
    'trash' => 'Trash',
    'spam' => 'Spam',
    _ => name.split(RegExp(r'[./]')).last,
  };

  factory MailFolder.fromJson(Map<String, dynamic> j) => MailFolder(
    id: '${j['id']}',
    name: '${j['name']}',
    role: '${j['role'] ?? 'custom'}',
    unread: (j['unread_count'] as num?)?.toInt() ?? 0,
    total: (j['total_count'] as num?)?.toInt() ?? 0,
  );
}

class MessageSummary {
  const MessageSummary({
    required this.id,
    required this.accountId,
    required this.folderId,
    required this.threadKey,
    required this.from,
    required this.subject,
    required this.date,
    this.snippet,
    this.isSeen = false,
    this.isFlagged = false,
    this.hasAttachments = false,
    this.isExternal = false,
  });

  final String id;
  final String accountId;
  final String folderId;
  final String threadKey;
  final Address from;
  final String subject;
  final DateTime date;
  final String? snippet;
  final bool isSeen;
  final bool isFlagged;
  final bool hasAttachments;
  final bool isExternal;

  MessageSummary copyWith({bool? isSeen, bool? isFlagged}) => MessageSummary(
    id: id,
    accountId: accountId,
    folderId: folderId,
    threadKey: threadKey,
    from: from,
    subject: subject,
    date: date,
    snippet: snippet,
    isSeen: isSeen ?? this.isSeen,
    isFlagged: isFlagged ?? this.isFlagged,
    hasAttachments: hasAttachments,
    isExternal: isExternal,
  );

  factory MessageSummary.fromJson(Map<String, dynamic> j) => MessageSummary(
    id: '${j['id']}',
    accountId: '${j['account_id'] ?? ''}',
    folderId: '${j['folder_id'] ?? ''}',
    threadKey: '${j['thread_key'] ?? ''}',
    from: Address.fromJson(
      (j['from'] as Map?)?.cast<String, dynamic>() ?? const {},
    ),
    subject: '${j['subject'] ?? ''}',
    date:
        DateTime.tryParse('${j['date']}')?.toLocal() ??
        DateTime.fromMillisecondsSinceEpoch(0),
    snippet: j['snippet'] as String?,
    isSeen: j['is_seen'] == true,
    isFlagged: j['is_flagged'] == true,
    hasAttachments: j['has_attachments'] == true,
    isExternal: j['is_external'] == true,
  );
}

class AttachmentInfo {
  const AttachmentInfo({
    required this.id,
    required this.filename,
    required this.mimeType,
    required this.size,
    this.isInline = false,
    this.warning,
  });
  final String id;
  final String filename;
  final String mimeType;
  final int size;
  final bool isInline;
  final String? warning;

  factory AttachmentInfo.fromJson(Map<String, dynamic> j) => AttachmentInfo(
    id: '${j['id']}',
    filename: '${j['filename'] ?? 'attachment'}',
    mimeType: '${j['mime_type'] ?? 'application/octet-stream'}',
    size: (j['size'] as num?)?.toInt() ?? 0,
    isInline: j['is_inline'] == true,
    warning: j['warning'] as String?,
  );
}

class SecurityInfo {
  const SecurityInfo({
    this.externalSender = false,
    this.lookalikeDomain = false,
    this.displayNameSpoof = false,
    this.replyToDiffers = false,
    this.spf,
    this.dkim,
    this.dmarc,
  });
  final bool externalSender;
  final bool lookalikeDomain;
  final bool displayNameSpoof;
  final bool replyToDiffers;
  final String? spf;
  final String? dkim;
  final String? dmarc;

  bool get authFailed => spf == 'fail' || dmarc == 'fail';
  bool get suspicious => lookalikeDomain || displayNameSpoof || authFailed;

  factory SecurityInfo.fromJson(Map<String, dynamic> j) => SecurityInfo(
    externalSender: j['external_sender'] == true,
    lookalikeDomain: j['lookalike_domain'] == true,
    displayNameSpoof: j['display_name_spoof'] == true,
    replyToDiffers: j['reply_to_differs'] == true,
    spf: j['spf'] as String?,
    dkim: j['dkim'] as String?,
    dmarc: j['dmarc'] as String?,
  );
}

class MessageDetail {
  const MessageDetail({
    required this.id,
    required this.threadKey,
    required this.subject,
    required this.from,
    required this.to,
    required this.cc,
    required this.date,
    required this.attachments,
    required this.security,
    this.html,
    this.text,
    this.remoteImagesBlocked = 0,
    this.remoteImagesAllowed = false,
    this.inlineImages = const {},
    this.isFlagged = false,
  });

  final String id;
  final String threadKey;
  final String subject;
  final Address from;
  final List<Address> to;
  final List<Address> cc;
  final DateTime date;
  final List<AttachmentInfo> attachments;
  final SecurityInfo security;
  final String? html;
  final String? text;
  final int remoteImagesBlocked;
  final bool remoteImagesAllowed;
  final Map<String, String> inlineImages;
  final bool isFlagged;

  static List<Address> _list(dynamic v) => (v as List? ?? const [])
      .map((e) => Address.fromJson((e as Map).cast<String, dynamic>()))
      .toList();

  factory MessageDetail.fromJson(Map<String, dynamic> j) {
    final headers = (j['headers'] as Map?)?.cast<String, dynamic>() ?? const {};
    final body = (j['body'] as Map?)?.cast<String, dynamic>() ?? const {};
    final inline = body['inline_images'];
    return MessageDetail(
      id: '${j['id']}',
      threadKey: '${j['thread_key'] ?? ''}',
      subject: '${j['subject'] ?? ''}',
      from: Address.fromJson(
        (headers['from'] as Map?)?.cast<String, dynamic>() ?? const {},
      ),
      to: _list(headers['to']),
      cc: _list(headers['cc']),
      date:
          DateTime.tryParse('${headers['date']}')?.toLocal() ??
          DateTime.fromMillisecondsSinceEpoch(0),
      attachments: (j['attachments'] as List? ?? const [])
          .map(
            (e) => AttachmentInfo.fromJson((e as Map).cast<String, dynamic>()),
          )
          .toList(),
      security: SecurityInfo.fromJson(
        (j['security'] as Map?)?.cast<String, dynamic>() ?? const {},
      ),
      html: body['html'] as String?,
      text: body['text'] as String?,
      remoteImagesBlocked:
          (body['remote_images_blocked'] as num?)?.toInt() ?? 0,
      remoteImagesAllowed: body['remote_images_allowed'] == true,
      inlineImages: inline is Map
          ? inline.map((k, v) => MapEntry('$k', '$v'))
          : const {},
      isFlagged: (j['flags'] as Map?)?['is_flagged'] == true,
    );
  }
}

class MessagePage {
  const MessagePage(this.items, this.nextCursor);
  final List<MessageSummary> items;
  final String? nextCursor;
}
