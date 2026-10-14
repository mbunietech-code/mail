<?php

namespace App\Security;

/**
 * Signs remote-image URLs so the image proxy only fetches URLs that MbunieMail itself
 * produced while sanitising a message the user chose to display with images
 * (SDD §8.5). Users cannot ask the proxy to fetch arbitrary URLs.
 */
class ImageProxySigner
{
    private const TTL_SECONDS = 3600;

    public function sign(string $url, ?int $expiresAt = null): string
    {
        $expiresAt ??= now()->getTimestamp() + self::TTL_SECONDS;

        return rtrim((string) config('app.url'), '/').'/api/v1/image-proxy?'.http_build_query([
            'u' => $url,
            'exp' => $expiresAt,
            'sig' => $this->signature($url, $expiresAt),
        ], '', '&', PHP_QUERY_RFC3986);
    }

    public function verify(string $url, int $expiresAt, string $signature): bool
    {
        return $expiresAt >= now()->getTimestamp()
            && hash_equals($this->signature($url, $expiresAt), $signature);
    }

    private function signature(string $url, int $expiresAt): string
    {
        return hash_hmac('sha256', $expiresAt.'|'.$url, $this->key());
    }

    /** Purpose-bound key derived from APP_KEY (never the raw app key). */
    private function key(): string
    {
        $appKey = (string) config('app.key');
        if (str_starts_with($appKey, 'base64:')) {
            $appKey = (string) base64_decode(substr($appKey, 7), true);
        }

        return hash_hkdf('sha256', $appKey, 32, 'mbuniemail:image-proxy:v1');
    }
}
