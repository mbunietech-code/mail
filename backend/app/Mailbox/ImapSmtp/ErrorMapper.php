<?php

namespace App\Mailbox\ImapSmtp;

use App\Mailbox\ProviderError;
use App\Mailbox\ProviderErrorCode;
use DirectoryTree\ImapEngine\Exceptions\ImapCommandException;
use DirectoryTree\ImapEngine\Exceptions\ImapConnectionTimedOutException;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\Exception\UnexpectedResponseException;
use Throwable;

/**
 * Translates library exceptions into ProviderError codes. Messages are kept for logs,
 * which pass through the redaction processor; credentials never appear in them.
 */
final class ErrorMapper
{
    public static function imap(Throwable $e, string $operation): ProviderError
    {
        if ($e instanceof ProviderError) {
            return $e;
        }

        $text = self::chainText($e);

        $code = match (true) {
            self::isTls($text) => ProviderErrorCode::TlsError,
            $e instanceof ImapConnectionTimedOutException, str_contains($text, 'timed out') => ProviderErrorCode::Timeout,
            self::isThrottle($text) => ProviderErrorCode::Throttled,
            $operation === 'login' && $e instanceof ImapCommandException => ProviderErrorCode::AuthFailed,
            str_contains($text, '[authenticationfailed]'), str_contains($text, 'authentication failed'), str_contains($text, 'invalid credentials') => ProviderErrorCode::AuthFailed,
            str_contains($text, '[overquota]'), str_contains($text, 'quota') => ProviderErrorCode::QuotaExceeded,
            str_contains($text, '[nonexistent]'), str_contains($text, 'mailbox doesn') , str_contains($text, 'no such mailbox') => ProviderErrorCode::MailboxGone,
            $e instanceof ImapCommandException => ProviderErrorCode::ProtocolError,
            default => ProviderErrorCode::Unreachable,
        };

        return new ProviderError($code, "IMAP {$operation} failed: ".mb_substr($e->getMessage(), 0, 300), $e);
    }

    public static function smtp(Throwable $e, bool $terminatorSent, string $operation = 'submit'): ProviderError
    {
        $text = self::chainText($e);
        $smtpCode = $e instanceof UnexpectedResponseException ? $e->getCode() : (int) $e->getCode();

        $code = match (true) {
            self::isTls($text) => ProviderErrorCode::TlsError,
            in_array($smtpCode, [530, 534, 535], true), str_contains($text, 'failed to authenticate') => ProviderErrorCode::AuthFailed,
            // A definitive reply means the server's decision is known.
            $e instanceof UnexpectedResponseException && $smtpCode === 552 => ProviderErrorCode::QuotaExceeded,
            $e instanceof UnexpectedResponseException && $smtpCode >= 500 => ProviderErrorCode::Rejected,
            $e instanceof UnexpectedResponseException && $smtpCode >= 400 => ProviderErrorCode::Throttled,
            // No reply after the terminator: the message may have been accepted.
            $terminatorSent => ProviderErrorCode::UnknownOutcome,
            str_contains($text, 'timed out'), str_contains($text, 'timeout') => ProviderErrorCode::Timeout,
            $e instanceof TransportExceptionInterface => ProviderErrorCode::Unreachable,
            default => ProviderErrorCode::ProtocolError,
        };

        return new ProviderError($code, "SMTP {$operation} failed (code {$smtpCode}): ".mb_substr($e->getMessage(), 0, 300), $e);
    }

    private static function chainText(Throwable $e): string
    {
        $parts = [];
        for ($x = $e; $x !== null; $x = $x->getPrevious()) {
            $parts[] = $x->getMessage();
        }

        return strtolower(implode(' | ', $parts));
    }

    private static function isTls(string $text): bool
    {
        return str_contains($text, 'certificate') || str_contains($text, 'ssl operation failed')
            || str_contains($text, 'crypto') || str_contains($text, 'tls');
    }

    private static function isThrottle(string $text): bool
    {
        return str_contains($text, '[limit]') || str_contains($text, '[unavailable]')
            || str_contains($text, 'too many') || str_contains($text, 'try again later') || str_contains($text, 'rate limit');
    }
}
