<?php

namespace App\Services\Mail;

/**
 * Attachment risk checks shared by reading and composing (SRS FR-SEND-003, risk R-10).
 */
final class AttachmentPolicy
{
    /** Extension → acceptable MIME prefixes, for mismatch detection. */
    private const EXPECTED = [
        'pdf' => ['application/pdf'],
        'png' => ['image/png'], 'jpg' => ['image/jpeg'], 'jpeg' => ['image/jpeg'], 'gif' => ['image/gif'], 'webp' => ['image/webp'],
        'txt' => ['text/plain'], 'csv' => ['text/csv', 'text/plain', 'application/csv'],
        'docx' => ['application/vnd.openxmlformats-officedocument', 'application/zip', 'application/octet-stream'],
        'xlsx' => ['application/vnd.openxmlformats-officedocument', 'application/zip', 'application/octet-stream'],
        'pptx' => ['application/vnd.openxmlformats-officedocument', 'application/zip', 'application/octet-stream'],
        'zip' => ['application/zip', 'application/x-zip-compressed'],
    ];

    public static function extension(string $filename): string
    {
        return strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    }

    public static function isRisky(string $filename): bool
    {
        $name = strtolower($filename);
        foreach (config('mbuniemail.outbox.risky_extensions') as $ext) {
            // Also catches "invoice.pdf.exe" and trailing-dot tricks.
            if (str_ends_with(rtrim($name, '. '), '.'.$ext)) {
                return true;
            }
        }

        return false;
    }

    public static function mimeMismatch(string $filename, string $detectedMime): bool
    {
        $expected = self::EXPECTED[self::extension($filename)] ?? null;
        if ($expected === null) {
            return false;
        }
        foreach ($expected as $prefix) {
            if (str_starts_with(strtolower($detectedMime), $prefix)) {
                return false;
            }
        }

        return true;
    }

    public static function warningFor(string $filename, string $mime): ?string
    {
        return match (true) {
            self::isRisky($filename) => 'EXECUTABLE_FILE', // includes "invoice.pdf.exe"
            self::mimeMismatch($filename, $mime) => 'TYPE_MISMATCH',
            default => null,
        };
    }
}
