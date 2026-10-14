<?php

namespace App\Mailbox;

use RuntimeException;
use Throwable;

/**
 * Raised by provider adapters. The message is for logs only (never shown to clients,
 * never containing credentials); clients receive the code and its remedy.
 */
class ProviderError extends RuntimeException
{
    public function __construct(
        public readonly ProviderErrorCode $errorCode,
        string $message = '',
        ?Throwable $previous = null,
        public readonly ?int $retryAfterSeconds = null,
    ) {
        parent::__construct($message !== '' ? $message : $errorCode->value, 0, $previous);
    }

    public static function of(ProviderErrorCode $code, string $message = '', ?Throwable $previous = null): self
    {
        return new self($code, $message, $previous);
    }
}
