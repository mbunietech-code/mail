<?php

namespace App\Mailbox\ImapSmtp;

/**
 * The only TLS settings MbunieMail uses for provider connections. Certificate and
 * hostname verification are always on and cannot be configured off (SRS NFR-SEC-001).
 * A custom CA is accepted only for the local test environment's fake server, and it is
 * trusted in addition to the system CA bundle so real providers keep working alongside it.
 */
final class TlsPolicy
{
    public const CRYPTO_METHOD = STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT;

    /** Common system bundle locations, tried after what OpenSSL itself reports. */
    private const SYSTEM_BUNDLE_FALLBACKS = [
        '/etc/ssl/certs/ca-certificates.crt',
        '/etc/pki/tls/certs/ca-bundle.crt',
        '/etc/ssl/cert.pem',
    ];

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
            $ssl['cafile'] = self::combinedBundle($caFile, self::systemBundle());
        }

        return ['ssl' => $ssl];
    }

    /** Test CA is honoured only outside production (see infra/scripts/dev-tls.sh). */
    private static function testCaFile(): ?string
    {
        $file = config('mbuniemail.providers.test_ca_file');

        return is_string($file) && $file !== '' && app()->environment('local', 'testing') && is_readable($file) ? $file : null;
    }

    /**
     * Writes (once per content) a PEM bundle of the system CAs plus the test CA under
     * storage/framework, so the test CA extends the trust store rather than replacing it.
     */
    public static function combinedBundle(string $testCa, ?string $systemBundle): string
    {
        $parts = [];
        foreach ([$systemBundle, $testCa] as $file) {
            if ($file !== null && ($pem = @file_get_contents($file)) !== false) {
                $parts[] = rtrim($pem)."\n";
            }
        }
        $pem = implode('', $parts);

        $path = storage_path('framework/mbuniemail-ca-bundle-'.hash('sha256', $pem).'.pem');
        if (! is_file($path)) {
            $tmp = $path.'.'.bin2hex(random_bytes(4)).'.tmp';
            if (@file_put_contents($tmp, $pem) === false || ! @rename($tmp, $path)) {
                @unlink($tmp);

                return $testCa;
            }
        }

        return $path;
    }

    /** The CA bundle OpenSSL would use by default, if one can be found on disk. */
    public static function systemBundle(): ?string
    {
        $locations = openssl_get_cert_locations();
        $candidates = [
            ini_get('openssl.cafile') ?: null,
            getenv($locations['default_cert_file_env'] ?? 'SSL_CERT_FILE') ?: null,
            $locations['default_cert_file'] ?? null,
            ...self::SYSTEM_BUNDLE_FALLBACKS,
        ];

        foreach ($candidates as $file) {
            if (is_string($file) && $file !== '' && is_file($file) && is_readable($file)) {
                return $file;
            }
        }

        return null;
    }
}
