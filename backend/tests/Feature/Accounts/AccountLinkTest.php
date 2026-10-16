<?php

namespace Tests\Feature\Accounts;

use App\Mailbox\Fake\FakeProvider;
use App\Mailbox\ProviderErrorCode;
use App\Mailbox\ProviderFactory;
use App\Models\AuditLog;
use App\Models\EmailAccount;
use App\Models\EncryptedCredential;
use App\Models\Folder;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class AccountLinkTest extends TestCase
{
    private const MAILBOX_PASSWORD = 'Mailbox-Pass-For-Test-77';

    private FakeProvider $provider;

    protected function setUp(): void
    {
        parent::setUp();
        $this->provider = new FakeProvider;
        ProviderFactory::fake($this->provider);
    }

    /** @return array{0: User, 1: array<string, string>} */
    private function userWithReauth(): array
    {
        $user = $this->makeUser();

        return [$user, $this->bearer($this->reauthedTokensFor($user)['access_token'])];
    }

    private function link(array $auth, string $email = 'developer@mt.co.tz'): TestResponse
    {
        return $this->freshRequest()->postJson('/api/v1/accounts', ['email_address' => $email, 'password' => self::MAILBOX_PASSWORD, 'display_name' => 'Dev'], $auth);
    }

    /** The mailbox these tests link (users also have the mailbox they signed in with). */
    private function linked(): Builder
    {
        return EmailAccount::query()->where('email_address', 'developer@mt.co.tz');
    }

    public function test_linking_verifies_stores_encrypted_credential_and_caches_folders(): void
    {
        [$user, $auth] = $this->userWithReauth();

        $response = $this->link($auth)->assertCreated()
            ->assertJsonPath('email_address', 'developer@mt.co.tz')
            ->assertJsonPath('provider', 'hostinger')
            ->assertJsonPath('status', 'active')
            ->assertJsonMissingPath('password');
        $this->assertStringNotContainsString(self::MAILBOX_PASSWORD, $response->getContent());

        $account = $this->linked()->sole();
        $this->assertSame($user->id, $account->user_id);

        // No plaintext anywhere in the credential row.
        $raw = DB::table('encrypted_credentials')->where('email_account_id', $account->id)->first();
        $this->assertStringNotContainsString(self::MAILBOX_PASSWORD, json_encode(array_map(fn ($v) => is_string($v) ? bin2hex($v) : $v, (array) $raw)).implode('', (array) $raw));
        $this->assertSame(1, EncryptedCredential::query()->where('email_account_id', $account->id)->count());

        $this->assertEqualsCanonicalizing(['inbox', 'sent', 'drafts', 'trash', 'spam'], Folder::query()->where('email_account_id', $account->id)->pluck('role')->all());
        $this->assertTrue(AuditLog::query()->where('action', 'account.linked')->exists());
    }

    public function test_linking_requires_recent_reauthentication(): void
    {
        $tokens = $this->tokensFor($this->makeUser());

        $this->link($this->bearer($tokens['access_token']))->assertStatus(403)->assertJsonPath('code', 'REAUTH_REQUIRED');
    }

    public function test_only_company_domains_can_be_linked(): void
    {
        [, $auth] = $this->userWithReauth();

        $this->link($auth, 'someone@gmail.com')->assertStatus(422)->assertJsonPath('code', 'DOMAIN_NOT_ALLOWED');
    }

    public function test_rejected_mailbox_password_returns_actionable_error_and_creates_nothing(): void
    {
        [, $auth] = $this->userWithReauth();
        $this->provider->failOn('verify', ProviderErrorCode::AuthFailed);

        $this->link($auth)->assertStatus(422)
            ->assertJsonPath('code', 'PROVIDER_AUTH_FAILED')
            ->assertJsonPath('detail', ProviderErrorCode::AuthFailed->remedy());

        $this->assertSame(0, $this->linked()->count());
        $this->assertSame(1, EncryptedCredential::query()->count()); // only the sign-in mailbox
        $this->assertTrue(AuditLog::query()->where('action', 'account.verification_failed')->exists());
    }

    /** SEC-T50 */
    public function test_provider_outage_is_reported_distinctly(): void
    {
        [, $auth] = $this->userWithReauth();
        $this->provider->failOn('verify', ProviderErrorCode::Throttled);

        $this->link($auth)->assertStatus(503)->assertJsonPath('code', 'PROVIDER_THROTTLED')->assertHeader('Retry-After');
    }

    public function test_duplicate_link_is_rejected(): void
    {
        [, $auth] = $this->userWithReauth();
        $this->link($auth)->assertCreated();

        $this->link($auth)->assertStatus(409)->assertJsonPath('code', 'ACCOUNT_ALREADY_LINKED');
    }

    /** Linking cannot be used as an oracle to brute-force mailbox passwords. */
    public function test_link_attempts_are_rate_limited(): void
    {
        [, $auth] = $this->userWithReauth();
        $this->provider->failOn('verify', ProviderErrorCode::AuthFailed);

        for ($i = 0; $i < 5; $i++) {
            $this->link($auth)->assertStatus(422);
        }
        $this->link($auth)->assertStatus(429);
    }

    /** SEC-T13: every account route hides other users' accounts. */
    public function test_users_cannot_reach_other_users_accounts(): void
    {
        [, $ownerAuth] = $this->userWithReauth();
        $id = $this->link($ownerAuth)->json('id');

        [, $otherAuth] = $this->userWithReauth();
        $requests = [
            ['GET', "/api/v1/accounts/{$id}"],
            ['PATCH', "/api/v1/accounts/{$id}", ['display_name' => 'x']],
            ['GET', "/api/v1/accounts/{$id}/diagnostics"],
            ['GET', "/api/v1/accounts/{$id}/folders"],
            ['PUT', "/api/v1/accounts/{$id}/credential", ['password' => 'x']],
            ['DELETE', "/api/v1/accounts/{$id}"],
        ];
        foreach ($requests as $request) {
            [$method, $uri] = $request;
            $this->freshRequest()->json($method, $uri, $request[2] ?? [], $otherAuth)->assertStatus(404);
        }

        // The other user sees only their own sign-in mailbox.
        $this->freshRequest()->getJson('/api/v1/accounts', $otherAuth)->assertOk()->assertJsonCount(1, 'data')->assertJsonMissing(['id' => $id]);
        $this->assertSame(1, $this->linked()->count());
    }

    /** SEC-T47 */
    public function test_admin_role_gives_no_access_to_employee_accounts(): void
    {
        [, $ownerAuth] = $this->userWithReauth();
        $id = $this->link($ownerAuth)->json('id');

        $admin = $this->makeUser([], 'super_admin');
        $secret = $this->enableMfa($admin);
        $mfaToken = $this->freshRequest()->login($admin)->json('mfa_token');
        $access = $this->freshRequest()->postJson('/api/v1/auth/mfa/verify', ['code' => $this->totp($secret)], $this->bearer($mfaToken))->json('access_token');

        $this->freshRequest()->getJson("/api/v1/accounts/{$id}/folders", $this->bearer($access))->assertStatus(404);
    }

    public function test_folder_listing_puts_standard_folders_first(): void
    {
        [, $auth] = $this->userWithReauth();
        $id = $this->link($auth)->json('id');

        $roles = $this->freshRequest()->getJson("/api/v1/accounts/{$id}/folders", $auth)->assertOk()->json('data.*.role');
        $this->assertSame(['inbox', 'sent', 'drafts', 'trash', 'spam'], array_slice($roles, 0, 5));
    }

    public function test_updating_credential_reverifies_and_clears_error_state(): void
    {
        [, $auth] = $this->userWithReauth();
        $id = $this->link($auth)->json('id');
        EmailAccount::query()->whereKey($id)->update(['status' => 'auth_failed', 'last_error_code' => 'AUTH_FAILED']);

        $this->freshRequest()->getJson("/api/v1/accounts/{$id}/diagnostics", $auth)
            ->assertJsonPath('status', 'auth_failed')
            ->assertJsonPath('remedy', ProviderErrorCode::AuthFailed->remedy());

        $this->freshRequest()->putJson("/api/v1/accounts/{$id}/credential", ['password' => 'New-Mailbox-Pass-1'], $auth)
            ->assertOk()->assertJsonPath('status', 'active')->assertJsonPath('last_error_code', null);
    }

    /** PRIV-T01 (unlink) */
    public function test_unlinking_purges_credential_and_cached_data(): void
    {
        [, $auth] = $this->userWithReauth();
        $id = $this->link($auth)->json('id');

        $this->freshRequest()->deleteJson("/api/v1/accounts/{$id}", [], $auth)->assertNoContent();

        $this->assertSame(0, $this->linked()->count());
        $this->assertSame(0, EncryptedCredential::query()->where('email_account_id', $id)->count());
        $this->assertSame(0, Folder::query()->where('email_account_id', $id)->count());
        $this->assertTrue(AuditLog::query()->where('action', 'account.unlinked')->exists());
    }
}
