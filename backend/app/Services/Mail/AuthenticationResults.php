<?php

namespace App\Services\Mail;

/**
 * Extracts SPF/DKIM/DMARC verdicts from an Authentication-Results header (RFC 8601)
 * for display (risk R-10). Only the verdict words are kept.
 */
class AuthenticationResults
{
    private const VERDICTS = ['pass', 'fail', 'softfail', 'neutral', 'none', 'temperror', 'permerror', 'policy'];

    /** @return array<string, string>|null */
    public function parse(?string $header): ?array
    {
        if ($header === null || trim($header) === '') {
            return null;
        }

        $out = [];
        foreach (['spf', 'dkim', 'dmarc'] as $method) {
            if (preg_match('/\b'.$method.'\s*=\s*([a-z]+)/i', $header, $m) && in_array(strtolower($m[1]), self::VERDICTS, true)) {
                $out[$method] = strtolower($m[1]);
            }
        }

        return $out === [] ? null : $out;
    }
}
