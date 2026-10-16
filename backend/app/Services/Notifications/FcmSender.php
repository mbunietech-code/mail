<?php

namespace App\Services\Notifications;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Minimal Firebase Cloud Messaging (HTTP v1) client for Android push (FR-NOTIF-001).
 * Authenticates with a service-account JWT; only identifiers and the privacy-filtered
 * text from NewMailPayload are ever sent (NFR-SEC-005).
 */
class FcmSender
{
    public const SENT = 'sent';

    public const INVALID_TOKEN = 'invalid_token';

    public const FAILED = 'failed';

    private const SCOPE = 'https://www.googleapis.com/auth/firebase.messaging';

    /** @var array{project_id: string, client_email: string, private_key: string, token_uri: string}|null|false */
    private array|null|false $credentials = false;

    public function isConfigured(): bool
    {
        return $this->credentials() !== null;
    }

    /**
     * @param  array{title: string, body: string, visibility: string, data: array<string, string>}  $payload
     * @return self::SENT|self::INVALID_TOKEN|self::FAILED
     */
    public function send(string $deviceToken, array $payload): string
    {
        $creds = $this->credentials();
        if ($creds === null) {
            return self::FAILED;
        }

        $response = Http::withToken($this->accessToken($creds))
            ->timeout(10)
            ->post("https://fcm.googleapis.com/v1/projects/{$creds['project_id']}/messages:send", [
                'message' => [
                    'token' => $deviceToken,
                    // Data-only: the app renders the notification (logo, brand colour,
                    // lock-screen privacy) instead of the plain system default.
                    'data' => $payload['data'] + ['title' => $payload['title'], 'body' => $payload['body'], 'visibility' => $payload['visibility']],
                    'android' => [
                        'priority' => 'HIGH',
                        'ttl' => '86400s',
                        // One pending alert per account; newer mail replaces it.
                        'collapse_key' => 'mail-'.$payload['data']['account_id'],
                    ],
                ],
            ]);

        if ($response->successful()) {
            return self::SENT;
        }

        $status = (string) $response->json('error.status');
        if ($response->status() === 404 || $status === 'UNREGISTERED' || ($status === 'INVALID_ARGUMENT' && str_contains((string) $response->json('error.message'), 'registration token'))) {
            return self::INVALID_TOKEN;
        }

        if ($response->status() === 401) {
            Cache::forget($this->cacheKey($creds));
        }
        Log::warning('FCM send failed', ['http_status' => $response->status(), 'fcm_status' => $status]);

        return self::FAILED;
    }

    /** @param  array{project_id: string, client_email: string, private_key: string, token_uri: string}  $creds */
    private function accessToken(array $creds): string
    {
        return Cache::remember($this->cacheKey($creds), now()->addMinutes(50), function () use ($creds) {
            $now = time();
            $segments = [
                self::base64Url(json_encode(['alg' => 'RS256', 'typ' => 'JWT'], JSON_THROW_ON_ERROR)),
                self::base64Url(json_encode([
                    'iss' => $creds['client_email'],
                    'scope' => self::SCOPE,
                    'aud' => $creds['token_uri'],
                    'iat' => $now,
                    'exp' => $now + 3600,
                ], JSON_THROW_ON_ERROR)),
            ];
            openssl_sign(implode('.', $segments), $signature, $creds['private_key'], OPENSSL_ALGO_SHA256);
            $segments[] = self::base64Url($signature);

            return (string) Http::asForm()->timeout(10)->post($creds['token_uri'], [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion' => implode('.', $segments),
            ])->throw()->json('access_token');
        });
    }

    /** @return array{project_id: string, client_email: string, private_key: string, token_uri: string}|null */
    private function credentials(): ?array
    {
        if ($this->credentials !== false) {
            return $this->credentials;
        }

        $path = (string) config('mbuniemail.push.fcm_credentials');
        $json = $path !== '' && is_readable($path) ? json_decode((string) file_get_contents($path), true) : null;

        return $this->credentials = is_array($json) && isset($json['project_id'], $json['client_email'], $json['private_key'])
            ? [
                'project_id' => (string) $json['project_id'],
                'client_email' => (string) $json['client_email'],
                'private_key' => (string) $json['private_key'],
                'token_uri' => (string) ($json['token_uri'] ?? 'https://oauth2.googleapis.com/token'),
            ]
            : null;
    }

    /** @param  array{client_email: string}  $creds */
    private function cacheKey(array $creds): string
    {
        return 'fcm:access-token:'.sha1($creds['client_email']);
    }

    private static function base64Url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
