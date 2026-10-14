<?php

namespace App\Services\Mail;

use App\Models\MessageMetadata;
use App\Support\CompanyDomains;

/**
 * Phishing indicators shown in the reader (risk R-10). These are warnings, not
 * verdicts: a client cannot prove a sender is genuine.
 */
class SenderAssessment
{
    /** @return array<string, mixed> */
    public function assess(MessageMetadata $message): array
    {
        $allowed = CompanyDomains::all();
        $fromDomain = self::domain($message->from_email);
        $external = $fromDomain === null || ! in_array($fromDomain, $allowed, true);

        $lookalike = false;
        if ($external && $fromDomain !== null) {
            foreach ($allowed as $domain) {
                if (levenshtein($fromDomain, $domain) <= 2 || self::skeleton($fromDomain) === self::skeleton($domain)) {
                    $lookalike = true;
                }
            }
        }

        // "CEO <ceo@mt.co.tz>" as a display name on an external address.
        $displayNameSpoof = $external && $message->from_name !== null
            && preg_match('/@('.implode('|', array_map('preg_quote', $allowed)).')\b/i', $message->from_name) === 1;

        $replyTo = $message->reply_to_json[0]['email'] ?? null;
        $replyToDiffers = $replyTo !== null && self::domain($replyTo) !== $fromDomain;

        $auth = $message->auth_results ?? [];

        return [
            'external_sender' => $external,
            'lookalike_domain' => $lookalike,
            'display_name_spoof' => $displayNameSpoof,
            'reply_to_differs' => $replyToDiffers,
            'spf' => $auth['spf'] ?? null,
            'dkim' => $auth['dkim'] ?? null,
            'dmarc' => $auth['dmarc'] ?? null,
        ];
    }

    private static function domain(?string $email): ?string
    {
        $at = $email === null ? false : strrpos($email, '@');

        return $at === false ? null : strtolower(substr((string) $email, $at + 1));
    }

    /** Collapses common homoglyph substitutions (rn→m, 0→o, 1→l, vv→w). */
    private static function skeleton(string $domain): string
    {
        return strtr(str_replace(['rn', 'vv'], ['m', 'w'], $domain), ['0' => 'o', '1' => 'l', '-' => '']);
    }
}
