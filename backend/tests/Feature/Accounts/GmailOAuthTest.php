<?php

namespace Tests\Feature\Accounts;

use App\Mailbox\Data\Address;
use App\Mailbox\Data\OutgoingMessage;
use App\Mailbox\Gmail\GmailProvider;
use App\Mailbox\Gmail\GoogleTokenManager;
use App\Mailbox\ProviderError;
use App\Mailbox\ProviderErrorCode;
use App\Models\AuditLog;
use App\Models\EmailAccount;
use App\Models\OAuthState;
use App\Models\User;
use App\Security\CredentialVault;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GmailOAuthTest extends TestCase
{
    private const SCOPE = 'https://www.googleapis.com/auth/gmail.modify';

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'mbuniemail.gmail.enabled' => true,
            'mbuniemail.gmail.client_id' => 'test-client.apps.googleusercontent.com',
            'mbuniemail.gmail.client_secret' => 'test-client-secret',
            'mbuniemail.gmail.redirect_uri' => 'https://api.mbuniemail.test/api/v1/oauth/google/callback',
            'mbuniemail.app_link_url' => 'https://app.mbuniemail.test',
        ]);
        DB::table('provider_profiles')->where('key', 'gmail')->update(['enabled' => true]);
    }

    private function fakeGoogle(string $scope = self::SCOPE, bool $refreshToken = true): void
    {
        $this->resetHttpFakes([
            'oauth2.googleapis.com/token' => Http::response(array_filter([
                'access_token' => 'ya29.test-access',
                'refresh_token' => $refreshToken ? '1//test-refresh' : null,
                'expires_in' => 3599,
                'scope' => $scope,
                'token_type' => 'Bearer',
            ])),
            'oauth2.googleapis.com/revoke' => Http::response('', 200),
            'gmail.googleapis.com/gmail/v1/users/me/profile' => Http::response(['emailAddress' => 'Someone@MT.co.tz', 'historyId' => '100']),
            'gmail.googleapis.com/gmail/v1/users/me/labels' => Http::response(['labels' => [
                ['id' => 'INBOX', 'name' => 'INBOX'], ['id' => 'SENT', 'name' => 'SENT'], ['id' => 'UNREAD', 'name' => 'UNREAD'],
                ['id' => 'CATEGORY_SOCIAL', 'name' => 'CATEGORY_SOCIAL'], ['id' => 'Label_1', 'name' => 'Clients'],
            ]]),
        ]);
    }

    /** @return array{0: User, 1: array<string, string>} */
    private function signedInSession(?User $user = null): array
    {
        $user ??= $this->makeUser();

        return [$user, $this->bearer($this->freshRequest()->reauthedTokensFor($user)['access_token'])];
    }

    /** @return array{url: string, state: string} */
    private function start(array $auth): array
    {
        $url = $this->freshRequest()->postJson('/api/v1/accounts/gmail/authorize', [], $auth)->assertOk()->json('authorization_url');
        parse_str((string) parse_url($url, PHP_URL_QUERY), $q);

        return ['url' => $url, 'state' => $q['state']];
    }

    private function hitCallback(string $state, string $code = 'auth-code-123'): string
    {
        return (string) $this->freshRequest()->get('/api/v1/oauth/google/callback?'.http_build_query(['code' => $code, 'state' => $state]))
            ->assertRedirect()->headers->get('Location');
    }

    private static function fragment(string $location): array
    {
        parse_str((string) parse_url($location, PHP_URL_FRAGMENT), $f);

        return $f;
    }

    /** Gmail accounts only (users also have the mailbox they signed in with). */
    private function gmailAccounts(): Builder
    {
        return EmailAccount::query()->whereHas('providerProfile', fn ($q) => $q->where('driver', 'gmail'));
    }

    public function test_gmail_is_unavailable_when_feature_flag_is_off(): void
    {
        config(['mbuniemail.gmail.enabled' => false]);
        [, $auth] = $this->signedInSession();

        $this->postJson('/api/v1/accounts/gmail/authorize', [], $auth)->assertStatus(404)->assertJsonPath('code', 'GMAIL_DISABLED');
    }

    public function test_authorization_url_uses_pkce_minimal_scope_and_single_use_state(): void
    {
        [, $auth] = $this->signedInSession();
        ['url' => $url, 'state' => $state] = $this->start($auth);
        parse_str((string) parse_url($url, PHP_URL_QUERY), $q);

        $this->assertStringStartsWith('https://accounts.google.com/o/oauth2/v2/auth?', $url);
        $this->assertSame('S256', $q['code_challenge_method']);
        $this->assertSame(43, strlen($q['code_challenge']));
        $this->assertSame(self::SCOPE, $q['scope']);
        $this->assertSame('offline', $q['access_type']);
        $this->assertSame(config('mbuniemail.gmail.redirect_uri'), $q['redirect_uri']);
        $this->assertSame(64, strlen($state));
        $this->assertNotNull(OAuthState::query()->find(hash('sha256', $state)), 'only the hash is stored');
        $this->assertNull(OAuthState::query()->find($state));
    }

    /** SEC-T51: the full flow never puts tokens in a URL. */
    public function test_full_flow_links_account_with_encrypted_tokens(): void
    {
        $this->fakeGoogle();
        [$user, $auth] = $this->signedInSession();
        ['state' => $state] = $this->start($auth);

        $location = $this->hitCallback($state);
        $this->assertStringStartsWith('https://app.mbuniemail.test/oauth/google#', $location);
        $this->assertStringNotContainsString('ya29', $location);
        $this->assertStringNotContainsString('auth-code-123', $location);
        $fragment = self::fragment($location);
        $this->assertSame('pending', $fragment['result']);

        Http::assertSent(fn (Request $r) => $r->url() === GoogleTokenManager::TOKEN_URL && $r['code_verifier'] !== null && strlen($r['code_verifier']) === 96);

        $this->freshRequest()->postJson('/api/v1/accounts/gmail/complete', ['completion' => $fragment['completion']], $auth)
            ->assertCreated()->assertJsonPath('provider', 'gmail')->assertJsonPath('email_address', 'someone@mt.co.tz');

        $account = $this->gmailAccounts()->sole();
        $this->assertSame($user->id, $account->user_id);
        $raw = (array) DB::table('encrypted_credentials')->where('email_account_id', $account->id)->first();
        $this->assertStringNotContainsString('1//test-refresh', implode('', array_map('strval', $raw)));
        $this->assertSame('1//test-refresh', app(CredentialVault::class)->open($account->credential)['refresh_token']->reveal());
        $this->assertEqualsCanonicalizing(['INBOX', 'SENT', 'Label_1'], $account->folders()->pluck('remote_name')->all(), 'system flags and categories hidden');
    }

    /** SEC-T26: account-linking CSRF — a completion code only works for the initiating session. */
    public function test_completion_by_a_different_user_or_session_is_rejected(): void
    {
        $this->fakeGoogle();
        [$initiator, $initiatorAuth] = $this->signedInSession();
        ['state' => $state] = $this->start($initiatorAuth);
        $completion = self::fragment($this->hitCallback($state))['completion'];

        [, $victimAuth] = $this->signedInSession();
        $this->freshRequest()->postJson('/api/v1/accounts/gmail/complete', ['completion' => $completion], $victimAuth)
            ->assertStatus(422)->assertJsonPath('code', 'OAUTH_COMPLETION_INVALID');

        // Single use: even the initiator cannot redeem it afterwards.
        $this->freshRequest()->postJson('/api/v1/accounts/gmail/complete', ['completion' => $completion], $initiatorAuth)->assertStatus(422);

        // Same user, different session (another device) is also rejected.
        ['state' => $state2] = $this->start($initiatorAuth);
        $completion2 = self::fragment($this->hitCallback($state2))['completion'];
        [, $otherSession] = $this->signedInSession($initiator);
        $this->freshRequest()->postJson('/api/v1/accounts/gmail/complete', ['completion' => $completion2], $otherSession)->assertStatus(422);

        $this->assertSame(0, $this->gmailAccounts()->count());
        $this->assertGreaterThanOrEqual(2, AuditLog::query()->where('action', 'account.gmail.completion_rejected')->count());
    }

    /** SEC-T26: state replay, unknown state and expiry. */
    public function test_state_cannot_be_replayed_forged_or_used_after_expiry(): void
    {
        $this->fakeGoogle();
        [, $auth] = $this->signedInSession();
        ['state' => $state] = $this->start($auth);

        $this->assertSame('pending', self::fragment($this->hitCallback($state))['result']);
        $this->assertSame('INVALID_STATE', self::fragment($this->hitCallback($state))['code'], 'replay');
        $this->assertSame('INVALID_STATE', self::fragment($this->hitCallback(str_repeat('a', 64)))['code'], 'forged');

        ['state' => $late] = $this->start($auth);
        $this->travel(11)->minutes();
        $this->assertSame('INVALID_STATE', self::fragment($this->hitCallback($late))['code'], 'expired');
    }

    public function test_insufficient_scope_or_missing_refresh_token_is_rejected(): void
    {
        $this->fakeGoogle(scope: 'openid email');
        [, $auth] = $this->signedInSession();
        $this->assertSame('SCOPE_NOT_GRANTED', self::fragment($this->hitCallback($this->start($auth)['state']))['code']);
    }

    public function test_user_denial_is_handled(): void
    {
        [, $auth] = $this->signedInSession();
        ['state' => $state] = $this->start($auth);

        $location = $this->freshRequest()->get('/api/v1/oauth/google/callback?error=access_denied&state='.$state)->headers->get('Location');
        $this->assertSame('ACCESS_DENIED', self::fragment((string) $location)['code']);
    }

    /** SEC-T27 */
    public function test_unlinking_revokes_tokens_at_google(): void
    {
        $this->fakeGoogle();
        [, $auth] = $this->signedInSession();
        $completion = self::fragment($this->hitCallback($this->start($auth)['state']))['completion'];
        $id = $this->freshRequest()->postJson('/api/v1/accounts/gmail/complete', ['completion' => $completion], $auth)->json('id');

        $this->freshRequest()->deleteJson("/api/v1/accounts/{$id}", [], $auth)->assertNoContent();

        Http::assertSent(fn (Request $r) => $r->url() === GoogleTokenManager::REVOKE_URL && $r['token'] === '1//test-refresh');
        $this->assertSame(0, $this->gmailAccounts()->count());
    }

    public function test_provider_refreshes_expired_tokens_and_maps_api_errors(): void
    {
        $this->fakeGoogle();
        [, $auth] = $this->signedInSession();
        $completion = self::fragment($this->hitCallback($this->start($auth)['state']))['completion'];
        $this->freshRequest()->postJson('/api/v1/accounts/gmail/complete', ['completion' => $completion], $auth)->assertCreated();
        $account = $this->gmailAccounts()->sole();

        $this->travel(2)->hours(); // access token expired
        $this->resetHttpFakes([
            'oauth2.googleapis.com/token' => Http::response(['access_token' => 'ya29.refreshed', 'expires_in' => 3599, 'scope' => self::SCOPE]),
            'gmail.googleapis.com/gmail/v1/users/me/labels/INBOX' => Http::sequence()
                ->push(['messagesTotal' => 12, 'messagesUnread' => 3])
                ->push(['error' => ['code' => 429, 'status' => 'RESOURCE_EXHAUSTED']], 429, ['Retry-After' => '30']),
            'gmail.googleapis.com/gmail/v1/users/me/messages/send' => fn () => throw new ConnectionException('timeout'),
        ]);

        $provider = new GmailProvider($account->refresh(), app(GoogleTokenManager::class));
        $this->assertSame(3, $provider->folderStatus('INBOX')->unseen);
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'labels/INBOX') && $r->hasHeader('Authorization', 'Bearer ya29.refreshed'));

        try {
            $provider->folderStatus('INBOX');
            $this->fail('expected throttling');
        } catch (ProviderError $e) {
            $this->assertSame(ProviderErrorCode::Throttled, $e->errorCode);
            $this->assertSame(30, $e->retryAfterSeconds);
        }

        // A send whose fate is unknown must never be reported as a safe-to-retry failure.
        try {
            $provider->submit(new OutgoingMessage(new Address('someone@mt.co.tz'), [new Address('x@example.org')], [], [], 's', null, 't', 'id@x'), "Subject: s\r\n\r\nt");
            $this->fail('expected unknown outcome');
        } catch (ProviderError $e) {
            $this->assertSame(ProviderErrorCode::UnknownOutcome, $e->errorCode);
        }
    }

    public function test_revoked_refresh_token_marks_auth_failure(): void
    {
        $this->fakeGoogle();
        [, $auth] = $this->signedInSession();
        $completion = self::fragment($this->hitCallback($this->start($auth)['state']))['completion'];
        $this->freshRequest()->postJson('/api/v1/accounts/gmail/complete', ['completion' => $completion], $auth)->assertCreated();

        $this->travel(2)->hours();
        $this->resetHttpFakes(['oauth2.googleapis.com/token' => Http::response(['error' => 'invalid_grant'], 400)]);

        try {
            (new GmailProvider($this->gmailAccounts()->sole(), app(GoogleTokenManager::class)))->verify();
            $this->fail('expected auth failure');
        } catch (ProviderError $e) {
            $this->assertSame(ProviderErrorCode::AuthFailed, $e->errorCode);
        }
    }
}
