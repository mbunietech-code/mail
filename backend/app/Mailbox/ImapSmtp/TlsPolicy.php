<?php

namespace App\Mailbox\ImapSmtp;

/**
 * The only TLS settings MbunieMail uses for provider connections. Certificate and
 * hostname verification are always on and cannot be configured off (SRS NFR-SEC-001).
 * A custom CA bundle is accepted only for the local test environment's fake server.
 */
final class TlsPolicy
{
    public const CRYPTO_METHOD = STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT;

    /**
     * @return array{ssl: array<string, mixed>}
     */
    public static function streamContext(string $host): array
    {
        $ssl = [
            'verify_peer' => true,
            'verify_peer_name' => true,
            'allow_self_signed' => false,
            'peer_name' => $host,
            'SNI_enabled' => true,
            'disable_compression' => true,
            'crypto_method' => self::CRYPTO_METHOD,
        ];

        $caFile = self::testCaFile();
        if ($caFile !== null) {
            $ssl['cafile'] = $caFile;
        }

        return ['ssl' => $ssl];
    }

    /** Test CA is honoured only outside production (see infra/scripts/dev-tls.sh). */
    private static function testCaFile(): ?string
    {
        $file = config('mbuniemail.providers.test_ca_file');

        return is_string($file) && $file !== '' && app()->environment('local', 'testing') && is_readable($file) ? $file : null;
    }
}
