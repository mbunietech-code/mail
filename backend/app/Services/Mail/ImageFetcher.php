<?php

namespace App\Services\Mail;

use App\Mailbox\HostGuard;
use App\Mailbox\ProviderError;
use GuzzleHttp\Psr7\UriResolver;
use GuzzleHttp\Psr7\Utils;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Fetches a remote image for the proxy (SDD §8.5, risk R-09). Every hop — including
 * redirects — is re-validated by HostGuard and the connection is pinned to the checked
 * IP, so neither a redirect nor DNS rebinding can reach internal services. Only real
 * raster images (sniffed, not trusted from headers) within the size limit are returned.
 */
class ImageFetcher
{
    public function __construct(private readonly HostGuard $guard) {}

    /** @return array{bytes: string, mime: string} */
    public function fetch(string $url): array
    {
        $cfg = config('mbuniemail.image_proxy');

        for ($hop = 0; $hop <= (int) $cfg['max_redirects']; $hop++) {
            $parts = parse_url($url);
            $scheme = strtolower((string) ($parts['scheme'] ?? ''));
            $host = strtolower((string) ($parts['host'] ?? ''));
            $port = (int) ($parts['port'] ?? ($scheme === 'https' ? 443 : 80));

            if (! in_array($scheme, ['http', 'https'], true) || $host === '' || ! in_array($port, [80, 443], true) || isset($parts['user']) || isset($parts['pass'])) {
                throw new RuntimeException('Image URL not allowed.');
            }

            try {
                $ip = $this->guard->resolve(trim($host, '[]'), permitDevLoopback: false);
            } catch (ProviderError $e) {
                throw new RuntimeException('Image host not allowed.', 0, $e);
            }

            try {
                $response = Http::withOptions([
                    'allow_redirects' => false,
                    'timeout' => (int) $cfg['timeout_seconds'],
                    'connect_timeout' => 3,
                    'curl' => [CURLOPT_RESOLVE => [sprintf('%s:%d:%s', trim($host, '[]'), $port, str_contains($ip, ':') ? "[{$ip}]" : $ip)]],
                    // Abort the transfer as soon as it exceeds the limit (not after buffering it).
                    'progress' => function ($total, $downloaded) use ($cfg) {
                        if ($downloaded > (int) $cfg['max_bytes']) {
                            throw new RuntimeException('Image too large.');
                        }
                    },
                ])->withHeaders([
                    'User-Agent' => 'MbunieMail-ImageProxy/1.0',
                    'Accept' => 'image/png,image/jpeg,image/gif,image/webp',
                ])->get($url);
            } catch (ConnectionException $e) {
                throw new RuntimeException('Image fetch failed.', 0, $e);
            }

            if ($response->redirect()) {
                $location = (string) $response->header('Location');
                if ($location === '') {
                    throw new RuntimeException('Redirect without location.');
                }
                $url = (string) UriResolver::resolve(Utils::uriFor($url), Utils::uriFor($location));

                continue;
            }

            if (! $response->successful()) {
                throw new RuntimeException('Image fetch failed ('.$response->status().').');
            }

            $bytes = $response->body();
            if (strlen($bytes) > (int) $cfg['max_bytes']) {
                throw new RuntimeException('Image too large.');
            }

            $mime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes) ?: '';
            if (! in_array($mime, $cfg['allowed_types'], true)) {
                throw new RuntimeException('Not an allowed image type.');
            }

            return ['bytes' => $bytes, 'mime' => $mime];
        }

        throw new RuntimeException('Too many redirects.');
    }
}
