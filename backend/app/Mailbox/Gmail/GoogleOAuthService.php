<?php

namespace App\Mailbox\Gmail;

use App\Exceptions\ApiException;
use App\Mailbox\ProviderError;
use App\Models\EmailAccount;
use App\Models\OAuthState;
use App\Models\ProviderProfile;
use App\Models\User;
use App\Services\Accounts\AccountService;
use App\Services\Audit\AuditLogger;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Gmail account linking with OAuth 2.0 Authorization Code + PKCE (SDD §8.3).
 *
 *  1. authorize():  the signed-in app session requests an authorisation URL; a single-use,
 *                   hashed state bound to the user and session family is stored.
 *  2. callback():   Google redirects the system browser here. The code is exchanged
 *                   server-side, scopes are checked and the tokens are parked encrypted.
 *                   The browser is sent to the app with a one-time completion code only.
 *  3. complete():   the SAME app session redeems the completion code, creating the account.
 *                   A callback finished in someone else's browser therefore cannot attach a
 *                   mailbox to the initiator's account (account-linking CSRF, risk R-29).
 */
class GoogleOAuthService
{
    public const AUTH_URL = 'https://accounts.google.com/o/oauth2/v2/auth';

    public const PROFILE_URL = 'https://gmail.googleapis.com/gmail/v1/users/me/profile';

    public function __construct(
        private readonly AccountService $accounts,
        private readonly GoogleTokenManager $tokens,
        private readonly AuditLogger $audit,
    ) {}

    public function ensureEnabled(): void
    {
        $cfg = config('mbuniemail.gmail');
        if (! $cfg['enabled'] || ! $cfg['client_id'] || ! $cfg['client_secret'] || ! $cfg['redirect_uri']) {
            throw new ApiException('GMAIL_DISABLED', 404, 'Gmail connection is not enabled.');
        }
    }

    public function authorize(User $user, string $sessionFamilyId): string
    {
        $this->ensureEnabled();

        $state = Str::random(64);
        $verifier = Str::random(96);

        $row = new OAuthState;
        $row->forceFill([
            'state_hash' => hash('sha256', $state),
            'user_id' => $user->id,
            'session_family_id' => $sessionFamilyId,
            'provider' => 'google',
            'pkce_verifier_enc' => $verifier,
            'expires_at' => now()->addMinutes((int) config('mbuniemail.gmail.state_ttl_minutes')),
        ])->save();

        return self::AUTH_URL.'?'.http_build_query([
            'client_id' => config('mbuniemail.gmail.client_id'),
            'redirect_uri' => config('mbuniemail.gmail.redirect_uri'),
            'response_type' => 'code',
            'scope' => implode(' ', config('mbuniemail.gmail.scopes')),
            'state' => $state,
            'code_challenge' => rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '='),
            'code_challenge_method' => 'S256',
            'access_type' => 'offline',
            'prompt' => 'consent',
            'include_granted_scopes' => 'false',
        ], '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * @return string URL the browser is redirected to (app link with result only)
     */
    public function callback(?string $code, ?string $state, ?string $error): string
    {
        $fail = fn (string $reason) => $this->appLink(['result' => 'error', 'code' => $reason]);

        if ($state === null || $state === '' || strlen($state) > 128) {
            return $fail('INVALID_STATE');
        }

        $row = DB::transaction(function () use ($state) {
            $row = OAuthState::query()->lockForUpdate()->find(hash('sha256', $state));
            if ($row === null || $row->consumed_at !== null || $row->expires_at->isPast() || $row->provider !== 'google') {
                return null;
            }
            $row->forceFill(['consumed_at' => now()])->save();

            return $row;
        });

        if ($row === null) {
            $this->audit->record('account.gmail.callback_rejected', 'denied', context: ['reason' => 'state']);

            return $fail('INVALID_STATE');
        }

        $user = User::query()->find($row->user_id);
        if ($user === null || ! $user->isActive() || $error !== null || $code === null || $code === '' || strlen($code) > 512) {
            $this->audit->record('account.gmail.link_failed', 'failure', $user, context: ['reason' => $error !== null ? 'user_denied' : 'invalid_request']);

            return $fail($error !== null ? 'ACCESS_DENIED' : 'INVALID_REQUEST');
        }

        try {
            $response = Http::asForm()->timeout(15)->post(GoogleTokenManager::TOKEN_URL, [
                'code' => $code,
                'client_id' => config('mbuniemail.gmail.client_id'),
                'client_secret' => config('mbuniemail.gmail.client_secret'),
                'redirect_uri' => config('mbuniemail.gmail.redirect_uri'),
                'grant_type' => 'authorization_code',
                'code_verifier' => $row->pkce_verifier_enc,
            ]);
        } catch (ConnectionException) {
            return $fail('PROVIDER_UNREACHABLE');
        }

        $granted = explode(' ', (string) $response->json('scope'));
        $missing = array_diff(config('mbuniemail.gmail.scopes'), $granted);
        if (! $response->successful() || $missing !== [] || ! $response->json('refresh_token') || ! $response->json('access_token')) {
            $this->audit->record('account.gmail.link_failed', 'failure', $user, context: ['reason' => $missing !== [] ? 'scope_missing' : 'token_exchange']);

            return $fail($missing !== [] ? 'SCOPE_NOT_GRANTED' : 'TOKEN_EXCHANGE_FAILED');
        }

        $completion = Str::random(64);
        $row->forceFill([
            'completion_hash' => hash('sha256', $completion),
            'pending_payload_enc' => [
                'access_token' => (string) $response->json('access_token'),
                'refresh_token' => (string) $response->json('refresh_token'),
                'expires_at' => now()->getTimestamp() + (int) $response->json('expires_in', 3600),
                'scope' => (string) $response->json('scope'),
            ],
            'expires_at' => now()->addMinutes(5),
        ])->save();

        return $this->appLink(['result' => 'pending', 'completion' => $completion]);
    }

    public function complete(User $user, ?string $sessionFamilyId, string $completion): EmailAccount
    {
        $this->ensureEnabled();

        $row = DB::transaction(function () use ($completion) {
            $row = OAuthState::query()->lockForUpdate()->where('completion_hash', hash('sha256', $completion))->first();
            if ($row === null || $row->completed_at !== null || $row->expires_at->isPast()) {
                return null;
            }
            $payload = $row->pending_payload_enc;
            $row->forceFill(['completed_at' => now(), 'pending_payload_enc' => null])->save();

            return [$row, $payload];
        });

        if ($row === null || $row[0]->user_id !== $user->id || $row[0]->session_family_id !== $sessionFamilyId || ! is_array($row[1])) {
            $this->audit->record('account.gmail.completion_rejected', 'denied', $user);

            throw new ApiException('OAUTH_COMPLETION_INVALID', 422, 'This Gmail connection request is invalid or has expired. Start again.');
        }

        $tokens = $row[1];
        $email = $this->profileEmail($tokens['access_token']);
        $profile = ProviderProfile::query()->where('driver', ProviderProfile::DRIVER_GMAIL)->where('enabled', true)->firstOrFail();

        $account = DB::transaction(function () use ($user, $email, $profile, $tokens) {
            $account = EmailAccount::query()->where('user_id', $user->id)->where('email_address', $email)->first() ?? new EmailAccount;
            $account->forceFill([
                'user_id' => $user->id,
                'email_address' => $email,
                'provider_profile_id' => $profile->id,
                'status' => EmailAccount::STATUS_ACTIVE,
                'last_error_code' => null,
                'capabilities' => (new GmailProvider($account, $this->tokens))->capabilities()->toArray(),
                'next_sync_at' => now(),
            ])->save();
            $this->tokens->store($account, $tokens);

            return $account;
        });

        try {
            $this->accounts->syncFolders($account, (new GmailProvider($account->refresh(), $this->tokens))->listFolders());
        } catch (ProviderError $e) {
            report($e); // folders are retried by sync
        }

        $this->audit->record('account.gmail.linked', 'success', $user, 'email_account', $account->id);

        return $account;
    }

    private function profileEmail(string $accessToken): string
    {
        $response = Http::withToken($accessToken)->acceptJson()->timeout(15)->get(self::PROFILE_URL);
        $email = strtolower((string) $response->json('emailAddress'));
        if (! $response->successful() || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new ApiException('PROVIDER_UNREACHABLE', 502, 'Could not read the Gmail profile.');
        }

        return $email;
    }

    /** @param array<string, string> $params */
    private function appLink(array $params): string
    {
        // Fragment, not query: keeps the completion code out of server/proxy logs.
        return config('mbuniemail.app_link_url').'/oauth/google#'.http_build_query($params, '', '&', PHP_QUERY_RFC3986);
    }
}
