<?php

namespace App\Mailbox\ImapSmtp;

use DirectoryTree\ImapEngine\Connection\ImapConnection;
use DirectoryTree\ImapEngine\Connection\Streams\StreamInterface;
use DirectoryTree\ImapEngine\Exceptions\ImapConnectionFailedException;
use Throwable;

/**
 * ImapEngine connection with MbunieMail's TLS policy applied to both implicit TLS and
 * STARTTLS (the library's defaults cannot set a minimum TLS version or CA bundle).
 * The socket connects to a pre-resolved IP (see HostGuard) while the certificate is
 * verified against $peerName. Plain-text transports are refused.
 */
class HardenedImapConnection extends ImapConnection
{
    public function __construct(StreamInterface $stream, private readonly string $peerName)
    {
        parent::__construct($stream);
    }

    /** @param array<string, mixed> $options */
    public function connect(string $host, ?int $port = null, array $options = []): void
    {
        $transport = strtolower((string) ($options['encryption'] ?? ''));
        if (! in_array($transport, ['ssl', 'starttls'], true)) {
            throw new \InvalidArgumentException('IMAP connections must use ssl or starttls.');
        }

        $this->capturingTlsErrors(fn () => parent::connect($host, $port, ['validate_cert' => true] + $options));
    }

    /**
     * @param  array<string, mixed>  $proxy
     * @return array<string, array<string, mixed>>
     */
    protected function getDefaultSocketOptions(string $transport, array $proxy = [], bool $validateCert = true): array
    {
        return TlsPolicy::streamContext($this->peerName);
    }

    public function startTls(): void
    {
        $this->send('STARTTLS', tag: $tag);
        $this->assertTaggedResponse($tag);
        $this->capturingTlsErrors(fn () => $this->stream->setSocketSetCrypto(true, TlsPolicy::CRYPTO_METHOD))
            ?: throw new ImapConnectionFailedException('TLS negotiation failed (STARTTLS).');
    }

    /**
     * PHP reports certificate failures only as warnings, which the library discards;
     * capture them so the failure is classified as a TLS error, not "unreachable".
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    private function capturingTlsErrors(callable $callback): mixed
    {
        $tlsError = null;
        set_error_handler(function (int $no, string $message) use (&$tlsError) {
            // Specific OpenSSL failures only — "Unable to connect to ssl://…" alone also
            // happens for refused connections and must stay "unreachable".
            if ($tlsError === null && preg_match('/certificate|SSL operation failed|enable crypto|peer name|handshake/i', $message)) {
                $tlsError = $message;
            }

            return true;
        });

        try {
            return $callback();
        } catch (Throwable $e) {
            if ($tlsError !== null) {
                throw new ImapConnectionFailedException('TLS verification failed: '.$tlsError, 0, $e);
            }

            throw $e;
        } finally {
            restore_error_handler();
        }
    }
}
