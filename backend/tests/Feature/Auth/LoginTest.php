<?php

namespace Tests\Feature\Auth;

use App\Mailbox\Fake\FakeProvider;
use App\Mailbox\ProviderErrorCode;
use App\Mailbox\ProviderFactory;
use App\Models\AuditLog;
use App\Models\EmailAccount;
use App\Models\EncryptedCredential;
use App\Models\Role;
use App\Models\User;
use App\Services\Settings;
use Tests\TestCase;

class LoginTest extends TestCase
{
    public function test_valid_credentials_issue_short_lived_access_and_refresh_tokens(): void
    {
        $user = $this->makeUser();

        $response = $this->login($user)->assertOk();

        $response->assertJsonPath('status', 'authenticated')
            ->assertJsonPath('access_expires_in', 900)
            ->assertJsonPath('user.email', $user->email)
            ->assertJsonStructure(['access_token', 'refresh_token', 'session_id']);
        $this->assertStringStartsWith('mbr_', $response->json('refresh_token'));

        $this->freshRequest()->getJson('/api/v1/me', $this->bearer($response->json('access_token')))
            ->assertOk()->assertJsonPath('email', $user->email);
    }

    public function test_first_sign_in_with_a_company_mailbox_creates_the_user_and_links_the_mailbox(): void
    {
        $this->postJson('/api/v1/auth/login', ['email' => 'Asha.Mussa@mt.co.tz', 'password' => self::PASSWORD])
            ->assertOk()->assertJsonPath('status', 'authenticated')->assertJsonPath('user.email', 'asha.mussa@mt.co.tz');

        $user = User::query()->where('email', 'asha.mussa@mt.co.tz')->sole();
        $this->assertSame('Asha Mussa', $user->name);
        $this->assertTrue($user->hasRole(Role::EMPLOYEE));
        $account = EmailAccount::query()->where('user_id', $user->id)->sole();
        $this->assertSame('asha.mussa@mt.co.tz', $account->email_address);
        $this->assertSame(1, EncryptedCredential::query()->where('email_account_id', $account->id)->count());
        $this->assertTrue(AuditLog::query()->where('action', 'auth.user.provisioned')->exists());
    }

    public function test_signing_in_again_refreshes_the_mailbox_instead_of_linking_it_twice(): void
    {
        $user = $this->makeUser();
        $this->login($user)->assertOk();
        EmailAccount::query()->where('user_id', $user->id)->update(['status' => 'auth_failed', 'last_error_code' => 'AUTH_FAILED']);

        $this->freshRequest()->login($user)->assertOk();

        $account = EmailAccount::query()->where('user_id', $user->id)->sole();
        $this->assertSame('active', $account->status);
        $this->assertNull($account->last_error_code);
    }

    public function test_rejected_mailbox_password_creates_no_user(): void
    {
        $this->postJson('/api/v1/auth/login', ['email' => 'newcomer@mt.co.tz', 'password' => 'wrong-mailbox-pass'])
            ->assertStatus(401)->assertJsonPath('code', 'INVALID_CREDENTIALS');

        $this->assertFalse(User::query()->where('email', 'newcomer@mt.co.tz')->exists());
    }

    public function test_provider_outage_at_sign_in_is_reported_and_creates_nothing(): void
    {
        ProviderFactory::fake((new FakeProvider)->failOn('verify', ProviderErrorCode::Throttled));

        $this->postJson('/api/v1/auth/login', ['email' => 'newcomer@mt.co.tz', 'password' => self::PASSWORD])
            ->assertStatus(503)->assertJsonPath('code', 'PROVIDER_THROTTLED');

        $this->assertFalse(User::query()->where('email', 'newcomer@mt.co.tz')->exists());
    }

    public function test_reauthentication_checks_the_mailbox_password(): void
    {
        $tokens = $this->tokensFor($this->makeUser());

        $this->freshRequest()->postJson('/api/v1/auth/reauth', ['password' => 'not-the-mailbox-pass'], $this->bearer($tokens['access_token']))
            ->assertStatus(401);
        $this->freshRequest()->postJson('/api/v1/auth/reauth', ['password' => self::PASSWORD], $this->bearer($tokens['access_token']))
            ->assertNoContent();
    }

    /** SEC-T33: unknown and known accounts produce identical failures. */
    public function test_invalid_password_and_unknown_user_return_the_same_generic_error(): void
    {
        $user = $this->makeUser();

        $wrong = $this->login($user, 'not-the-password')->assertStatus(401);
        $unknown = $this->postJson('/api/v1/auth/login', ['email' => 'nobody@mt.co.tz', 'password' => 'whatever-password'])->assertStatus(401);

        $strip = fn (array $body) => array_diff_key($body, ['request_id' => true]);
        $this->assertSame($strip($wrong->json()), $strip($unknown->json()));
        $this->assertSame('INVALID_CREDENTIALS', $wrong->json('code'));
    }

    public function test_failed_login_is_audited_without_the_password(): void
    {
        $user = $this->makeUser();
        $this->login($user, 'Secret-Attempt-123')->assertStatus(401);

        $log = AuditLog::query()->where('action', 'auth.login.failed')->sole();
        $this->assertSame($user->id, $log->actor_user_id);
        $this->assertStringNotContainsString('Secret-Attempt-123', json_encode($log->getAttributes()));
    }

    public function test_suspended_user_cannot_log_in(): void
    {
        $user = $this->makeUser(['status' => User::STATUS_SUSPENDED]);

        $this->login($user)->assertStatus(403)->assertJsonPath('code', 'ACCOUNT_DISABLED');
    }

    /** SEC-T01 */
    public function test_login_is_throttled_per_account_and_ip(): void
    {
        $user = $this->makeUser();

        for ($i = 0; $i < 5; $i++) {
            $this->login($user, 'wrong-password-'.$i)->assertStatus(401);
        }

        $this->login($user)->assertStatus(429)->assertHeader('Retry-After')->assertJsonPath('code', 'RATE_LIMITED');
    }

    /** SEC-T01: repeated failures lock the account temporarily, from any IP. */
    public function test_account_is_locked_after_repeated_failures(): void
    {
        $user = $this->makeUser();

        for ($i = 0; $i < 10; $i++) {
            $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.'.$i])->login($user, 'wrong-password')->assertStatus(401);
        }

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.9'])->login($user)
            ->assertStatus(423)->assertJsonPath('code', 'ACCOUNT_LOCKED')->assertHeader('Retry-After');
    }

    /** SEC-T02 */
    public function test_mfa_user_receives_only_an_mfa_pending_token(): void
    {
        $user = $this->makeUser();
        $this->enableMfa($user);

        $response = $this->login($user)->assertOk()->assertJsonPath('status', 'mfa_required')->assertJsonMissingPath('access_token');

        $this->freshRequest()->getJson('/api/v1/me', $this->bearer($response->json('mfa_token')))
            ->assertStatus(401)->assertJsonPath('code', 'MFA_REQUIRED');
    }

    /** FR-AUTH-008: administrators must always enrol MFA. */
    public function test_admin_without_mfa_is_forced_into_enrollment(): void
    {
        $admin = $this->makeUser([], Role::ADMIN);

        $response = $this->login($admin)->assertOk()->assertJsonPath('status', 'mfa_enrollment_required');

        $this->freshRequest()->getJson('/api/v1/me', $this->bearer($response->json('mfa_token')))
            ->assertStatus(403)->assertJsonPath('code', 'MFA_ENROLLMENT_REQUIRED');
    }

    public function test_organisation_wide_mfa_policy_forces_enrollment(): void
    {
        app(Settings::class)->set('mfa.required_for_all', true);
        $user = $this->makeUser();

        $this->login($user)->assertOk()->assertJsonPath('status', 'mfa_enrollment_required');
    }

    public function test_user_suspended_after_login_is_cut_off_immediately(): void
    {
        $user = $this->makeUser();
        $tokens = $this->tokensFor($user);
        $user->forceFill(['status' => User::STATUS_SUSPENDED])->save();

        $this->freshRequest()->getJson('/api/v1/me', $this->bearer($tokens['access_token']))
            ->assertStatus(401)->assertJsonPath('code', 'ACCOUNT_DISABLED');
    }

    public function test_logout_revokes_access_and_refresh_tokens(): void
    {
        $tokens = $this->tokensFor($this->makeUser());

        $this->freshRequest()->postJson('/api/v1/auth/logout', [], $this->bearer($tokens['access_token']))->assertNoContent();

        $this->freshRequest()->getJson('/api/v1/me', $this->bearer($tokens['access_token']))->assertStatus(401);
        $this->freshRequest()->postJson('/api/v1/auth/refresh', ['refresh_token' => $tokens['refresh_token']])->assertStatus(401);
    }
}
