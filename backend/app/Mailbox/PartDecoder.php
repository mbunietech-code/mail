<?php

namespace App\Mailbox;

/**
 * Decodes MIME part bodies (transfer encoding + charset) and builds list snippets.
 */
final class PartDecoder
{
    public static function decode(string $raw, ?string $encoding, ?string $charset = null): string
    {
        $data = match (strtolower((string) $encoding)) {
            'base64' => base64_decode(preg_replace('/\s+/', '', $raw) ?? '', false) ?: '',
            'quoted-printable' => quoted_printable_decode($raw),
            default => $raw,
        };

        if ($charset === null) {
            return $data;
        }

        return self::toUtf8($data, $charset);
    }

    public static function toUtf8(string $data, string $charset): string
    {
        $charset = strtoupper(trim($charset, " \"'"));
        if ($charset === '' || $charset === 'UTF-8' || $charset === 'US-ASCII') {
            return mb_check_encoding($data, 'UTF-8') ? $data : mb_convert_encoding($data, 'UTF-8', 'UTF-8');
        }

        $converted = @mb_convert_encoding($data, 'UTF-8', $charset);
        if ($converted !== false && $converted !== '') {
            return $converted;
        }

        $iconv = @iconv($charset, 'UTF-8//IGNORE', $data);

        return $iconv !== false ? $iconv : mb_convert_encoding($data, 'UTF-8', 'UTF-8');
    }

    /** Plain-text preview, at most $length characters (never HTML). */
    public static function snippet(?string $text, ?string $html, int $length = 200): ?string
    {
        $source = $text;
        if (($source === null || trim($source) === '') && $html !== null) {
            $withoutBlocks = preg_replace('#<(script|style|head)\b[^>]*>.*?</\1>#is', ' ', $html) ?? '';
            $source = html_entity_decode(strip_tags($withoutBlocks), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }
        if ($source === null) {
            return null;
        }

        // Drop quoted reply lines so previews show new content.
        $lines = array_filter(preg_split('/\R/u', $source) ?: [], fn ($l) => ! str_starts_with(ltrim($l), '>'));
        $clean = trim(preg_replace('/\s+/u', ' ', implode(' ', $lines)) ?? '');

        return $clean === '' ? null : mb_substr($clean, 0, $length);
    }

    /** Safe display filename: no paths, control characters or reserved characters. */
    public static function safeFilename(?string $name, string $fallback = 'attachment'): string
    {
        $name = (string) $name;
        $name = basename(str_replace('\\', '/', $name));
        $name = preg_replace('/[\x00-\x1F\x7F<>:"|?*]/u', '', $name) ?? '';
        $name = trim($name, ' .');

        return $name === '' ? $fallback : mb_substr($name, 0, 200);
    }
}
