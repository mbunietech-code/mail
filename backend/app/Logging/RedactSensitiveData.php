<?php

namespace App\Logging;

use Illuminate\Log\Logger;
use Monolog\Handler\ProcessableHandlerInterface;
use Monolog\LogRecord;
use Throwable;

/**
 * Monolog tap that masks secrets and mail content in log context and messages
 * (risk R-19, SRS NFR-SEC-005). Applied to every configured channel.
 */
class RedactSensitiveData
{
    private const KEY_PATTERN = '/pass(word)?|secret|token|authorization|cookie|otp|^code$|recovery|body|subject|snippet|credential|api[_-]?key|dek|kek/i';

    private const MASK = '[REDACTED]';

    public function __invoke(Logger $logger): void
    {
        foreach ($logger->getHandlers() as $handler) {
            if (! $handler instanceof ProcessableHandlerInterface) {
                continue;
            }
            $handler->pushProcessor(fn (LogRecord $record) => $record->with(
                message: self::scrubString($record->message),
                context: self::scrub($record->context),
                extra: self::scrub($record->extra),
            ));
        }
    }

    /**
     * @param  array<mixed>  $data
     * @return array<mixed>
     */
    public static function scrub(array $data, int $depth = 0): array
    {
        foreach ($data as $key => $value) {
            if (is_string($key) && preg_match(self::KEY_PATTERN, $key)) {
                $data[$key] = self::MASK;
            } elseif ($value instanceof Throwable) {
                $data[$key] = self::scrubThrowable($value, $depth);
            } elseif (is_array($value)) {
                $data[$key] = $depth < 5 ? self::scrub($value, $depth + 1) : self::MASK;
            } elseif (is_string($value)) {
                $data[$key] = self::scrubString($value);
            }
        }

        return $data;
    }

    /**
     * Exceptions are normalised by the formatter after processors run, so their
     * messages and traces would bypass string scrubbing; flatten them here instead.
     *
     * @return array<string, mixed>
     */
    private static function scrubThrowable(Throwable $e, int $depth): array
    {
        return array_filter([
            'class' => $e::class,
            'message' => self::scrubString($e->getMessage()),
            'code' => $e->getCode(),
            'file' => $e->getFile().':'.$e->getLine(),
            'trace' => self::scrubString($e->getTraceAsString()),
            'previous' => $e->getPrevious() !== null && $depth < 3 ? self::scrubThrowable($e->getPrevious(), $depth + 1) : null,
        ], fn ($v) => $v !== null);
    }

    public static function scrubString(string $value): string
    {
        return (string) preg_replace(
            [
                '/Bearer\s+[A-Za-z0-9|._~+\/=-]+/i',
                '/\b(mbm|mbr)_[A-Za-z0-9|_-]{16,}/',
                '/("?(password|token|secret|refresh_token|access_token)"?\s*[:=]\s*)"?[^",\s}]+"?/i',
            ],
            ['Bearer '.self::MASK, self::MASK, '$1'.self::MASK],
            $value,
        );
    }
}
