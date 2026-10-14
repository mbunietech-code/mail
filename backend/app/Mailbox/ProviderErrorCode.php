<?php

namespace App\Mailbox;

/** Provider-neutral failure taxonomy (SDD §5.3). Business logic depends only on these. */
enum ProviderErrorCode: string
{
    case AuthFailed = 'AUTH_FAILED';
    case TlsError = 'TLS_ERROR';
    case Unreachable = 'UNREACHABLE';
    case Timeout = 'TIMEOUT';
    case Throttled = 'THROTTLED';
    case NotSupported = 'NOT_SUPPORTED';
    case MailboxGone = 'MAILBOX_GONE';
    case MessageGone = 'MESSAGE_GONE';
    case QuotaExceeded = 'QUOTA_EXCEEDED';
    case Rejected = 'REJECTED';
    case UnknownOutcome = 'UNKNOWN_OUTCOME';
    case ProtocolError = 'PROTOCOL_ERROR';
    case HostNotAllowed = 'HOST_NOT_ALLOWED';

    /** Whether the same operation may be retried automatically (with backoff). */
    public function isRetryable(): bool
    {
        return in_array($this, [self::Unreachable, self::Timeout, self::Throttled, self::ProtocolError], true);
    }

    /** Human-readable remedy shown in diagnostics (no provider text). */
    public function remedy(): string
    {
        return match ($this) {
            self::AuthFailed => 'The mail server rejected the password. Update it in Accounts (it may have been changed in Hostinger).',
            self::TlsError => 'A secure connection to the mail server could not be verified. Contact IT; do not use untrusted networks.',
            self::Unreachable => 'The mail server is unreachable. MbunieMail will retry automatically.',
            self::Timeout => 'The mail server did not respond in time. MbunieMail will retry automatically.',
            self::Throttled => 'The mail provider is limiting requests. MbunieMail will retry later.',
            self::NotSupported => 'This action is not supported by your mail provider.',
            self::MailboxGone => 'The folder no longer exists on the server.',
            self::MessageGone => 'The message no longer exists on the server.',
            self::QuotaExceeded => 'The mailbox is full. Delete messages or contact IT.',
            self::Rejected => 'The mail server rejected the message.',
            self::UnknownOutcome => 'The connection dropped while sending, so delivery is uncertain. Check Sent before resending.',
            self::ProtocolError => 'The mail server returned an unexpected response. MbunieMail will retry.',
            self::HostNotAllowed => 'The configured mail server address is not allowed. Contact IT.',
        };
    }
}
