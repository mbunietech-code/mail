<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * An error with a stable machine-readable code, rendered as RFC 9457 problem+json.
 * `detail` must be safe to show to the client — never include secrets or provider text.
 */
class ApiException extends RuntimeException
{
    /**
     * @param  array<string, string>  $headers
     */
    public function __construct(
        public readonly string $errorCode,
        public readonly int $status,
        public readonly string $title,
        public readonly ?string $detail = null,
        public readonly array $headers = [],
    ) {
        parent::__construct($title);
    }

    public static function unauthenticated(string $code = 'UNAUTHENTICATED', string $title = 'Authentication required.'): self
    {
        return new self($code, 401, $title);
    }

    public static function forbidden(string $code = 'FORBIDDEN', string $title = 'This action is not permitted.'): self
    {
        return new self($code, 403, $title);
    }

    public static function tooManyAttempts(int $retryAfter, string $code = 'RATE_LIMITED', int $status = 429): self
    {
        return new self($code, $status, 'Too many attempts. Try again later.', null, ['Retry-After' => (string) max(1, $retryAfter)]);
    }
}
