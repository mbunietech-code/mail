<?php

namespace App\Mailbox\Gmail;

use App\Mailbox\ProviderError;
use App\Mailbox\ProviderErrorCode;
use App\Models\EmailAccount;
use App\Models\EncryptedCredential;
use App\Security\CredentialVault;
use App\Security\Secret;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Access/refresh token lifecycle for Gmail accounts. Tokens are stored only through the
 * CredentialVault; refreshes are serialised per account with a cache lock.
 */
class GoogleTokenManager
{
    public const TOKEN_URL = 'https://oauth2.googleapis.com/token';

    public const REVOKE_URL = 'https://oauth2.googleapis.com/revoke';

    public function __construct(private readonly CredentialVault $vault) {}

    public function accessToken(EmailAccount $account, bool $forceRefresh = false): Secret
    {
        $data = $this->load($account);
        if (! $forceRefresh && ($data['expires_at'] ?? 0) > now()->getTimestamp() + 300 && $data['access_token'] instanceof Secret) {
            return $data['access_token'];
        }

        return Cache::lock('gmail-refresh:'.$account->id, 30)->block(15, function () use ($account, $forceRefresh) {
            $data = $this->load($account->refresh());
            if (! $forceRefresh && ($data['expires_at'] ?? 0) > now()->getTimestamp() + 300 && $data['access_token'] instanceof Secret) {
                return $data['access_token'];
            }

            $refresh = $data['refresh_token'] ?? null;
            if (! $refresh instanceof Secret) {
                throw ProviderError::of(ProviderErrorCode::AuthFailed, 'No Google refresh token.');
            }

            try {
                $response = Http::asForm()->timeout(15)->post(self::TOKEN_URL, [
                    'client_id' => config('mbuniemail.gmail.client_id'),
                    'client_secret' => config('mbuniemail.gmail.client_secret'),
                    'refresh_token' => $refresh->reveal(),
                    'grant_type' => 'refresh_token',
                ]);
            } catch (ConnectionException $e) {
                throw ProviderError::of(ProviderErrorCode::Unreachable, 'Google token endpoint unreachable.', $e);
            }

            if ($response->status() === 400 || $response->status() === 401) {
                // invalid_grant: revoked by the user, password change, or expired.
                throw ProviderError::of(ProviderErrorCode::AuthFailed, 'Google refresh token rejected ('.$response->json('error', 'unknown').').');
            }
            if (! $response->successful()) {
                throw ProviderError::of($response->status() === 429 ? ProviderErrorCode::Throttled : ProviderErrorCode::Unreachable, 'Google token refresh failed ('.$response->status().').');
            }

            $this->store($account, [
                'access_token' => (string) $response->json('access_token'),
                'refresh_token' => (string) ($response->json('refresh_token') ?: $refresh->reveal()),
                'expires_at' => now()->getTimestamp() + (int) $response->json('expires_in', 3600),
                'scope' => (string) ($response->json('scope') ?: ($data['scope'] ?? '')),
            ]);

            return new Secret((string) $response->json('access_token'));
        });
    }

    /**
     * @param  array{access_token: string, refresh_token: string, expires_at: int, scope: string}  $tokens
     */
    public function store(EmailAccount $account, #[\SensitiveParameter] array $tokens): void
    {
        $sealed = $this->vault->seal($account->id, $tokens);
        EncryptedCredential::query()->updateOrInsert(
            ['email_account_id' => $account->id],
            ['kind' => 'oauth', ...$sealed, 'created_at' => now(), 'updated_at' => now()],
        );
        $account->unsetRelation('credential');
    }

    /** Best-effort revocation at Google before local deletion (FR-ACCT-005). */
    public function revoke(EmailAccount $account): bool
    {
        try {
            $data = $this->load($account);
            $token = $data['refresh_token'] ?? $data['access_token'] ?? null;
            if (! $token instanceof Secret) {
                return false;
            }

            return Http::asForm()->timeout(10)->post(self::REVOKE_URL, ['token' => $token->reveal()])->successful();
        } catch (\Throwable $e) {
            report($e);

            return false;
        }
    }

    /** @return array<string, mixed> */
    private function load(EmailAccount $account): array
    {
        $credential = $account->credential ?? throw ProviderError::of(ProviderErrorCode::AuthFailed, 'No stored Google credential.');

        return $this->vault->open($credential);
    }
}
