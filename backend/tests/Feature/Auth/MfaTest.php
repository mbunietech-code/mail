<?php

namespace Tests\Feature\Auth;

use App\Models\AuditLog;
use App\Models\Role;
use App\Models\User;
use App\Services\Auth\RecoveryCodeService;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

class MfaTest extends TestCase
{
    public function test_enrolment_with_a_full_token_requires_recent_reauthentication(): void
    {
        $tokens = $this->tokensFor($this->makeUser());

        $this->freshRequest()->postJson('/api/v1/auth/mfa/enroll', [], $this->bearer($tokens['access_token']))
            ->assertStatus(403)->assertJsonPath('code', 'REAUTH_REQUIRED');

        $this->freshRequest()->postJson('/api/v1/auth/reauth', ['password' => self::PASSWORD], $this->bearer($tokens['access_token']))->assertNoContent();

        $this->freshRequest()->postJson('/api/v1/auth/mfa/enroll', [], $this->bearer($tokens['access_token']))
            ->assertOk()->assertJsonStructure(['secret', 'otpauth_uri']);
    }

    public function test_full_enrolment_returns_ten_single_use_recovery_codes_and_stores_secret_encrypted(): void
    {
        $user = $this->makeUser();
        $tokens = $this->tokensFor($user);
        $auth = $this->bearer($tokens['access_token']);
        $this->freshRequest()->postJson('/api/v1/auth/reauth', ['password' => self::PASSWORD], $auth);

        $secret = $this->freshRequest()->postJson('/api/v1/auth/mfa/enroll', [], $auth)->json('secret');
        $this->freshRequest()->postJson('/api/v1/auth/mfa/confirm', ['code' => '000000'], $auth)->assertStatus(422);

        $codes = $this->freshRequest()->postJson('/api/v1/auth/mfa/confirm', ['code' => $this->totp($secret)], $auth)
            ->assertOk()->assertJsonCount(10, 'recovery_codes')->json('recovery_codes');

        $raw = User::query()->toBase()->where('id', $user->id)->first();
        $this->assertNotNull($raw->mfa_confirmed_at);
        $this->assertStringNotContainsString($secret, (string) $raw->mfa_secret);
        $this->assertNotContains($codes[0], $user->recoveryCodes()->pluck('code_hash')->all());
        $this->assertTrue(AuditLog::query()->where('action', 'auth.mfa.enabled')->exists());
    }

    public function test_login_with_totp_completes_after_password(): void
    {
        $user = $this->makeUser();
        $secret = $this->enableMfa($user);
        $mfaToken = $this->login($user)->json('mfa_token');

        $this->freshRequest()->postJson('/api/v1/auth/mfa/verify', ['code' => $this->totp($secret)], $this->bearer($mfaToken))
            ->assertOk()->assertJsonPath('status', 'authenticated');

        // The pending token is consumed.
        $this->freshRequest()->postJson('/api/v1/auth/mfa/verify', ['code' => $this->totp($secret)], $this->bearer($mfaToken))->assertStatus(401);
    }

    /** SEC-T10 */
    public function test_a_totp_code_cannot_be_replayed(): void
    {
        $user = $this->makeUser();
        $secret = $this->enableMfa($user);
        $code = $this->totp($secret);

        $first = $this->login($user)->json('mfa_token');
        $this->freshRequest()->postJson('/api/v1/auth/mfa/verify', ['code' => $code], $this->bearer($first))->assertOk();

        $second = $this->freshRequest()->login($user)->json('mfa_token');
        $this->freshRequest()->postJson('/api/v1/auth/mfa/verify', ['code' => $code], $this->bearer($second))
            ->assertStatus(401)->assertJsonPath('code', 'INVALID_MFA_CODE');
    }

    public function test_recovery_code_works_once(): void
    {
        $user = $this->makeUser();
        $this->enableMfa($user);
        $codes = app(RecoveryCodeService::class)->regenerate($user);

        $token = $this->login($user)->json('mfa_token');
        $this->freshRequest()->postJson('/api/v1/auth/mfa/verify', ['recovery_code' => strtoupper($codes[0])], $this->bearer($token))->assertOk();

        $token = $this->freshRequest()->login($user)->json('mfa_token');
        $this->freshRequest()->postJson('/api/v1/auth/mfa/verify', ['recovery_code' => $codes[0]], $this->bearer($token))->assertStatus(401);
        $this->assertTrue(AuditLog::query()->where('action', 'auth.mfa.recovery_code_used')->exists());
    }

    /** SEC-T32 */
    public function test_mfa_verification_is_rate_limited(): void
    {
        $user = $this->makeUser();
        $this->enableMfa($user);
        $token = $this->login($user)->json('mfa_token');

        for ($i = 0; $i < 5; $i++) {
            $this->freshRequest()->postJson('/api/v1/auth/mfa/verify', ['code' => '000000'], $this->bearer($token))->assertStatus(401);
        }

        $this->freshRequest()->postJson('/api/v1/auth/mfa/verify', ['code' => '000000'], $this->bearer($token))->assertStatus(429);
    }

    public function test_forced_enrolment_completes_login_and_returns_tokens(): void
    {
        $admin = $this->makeUser([], Role::ADMIN);
        $token = $this->login($admin)->json('mfa_token');
        $auth = $this->bearer($token);

        $secret = $this->freshRequest()->postJson('/api/v1/auth/mfa/enroll', [], $auth)->assertOk()->json('secret');
        $response = $this->freshRequest()->postJson('/api/v1/auth/mfa/confirm', ['code' => $this->totp($secret)], $auth)->assertOk();

        $this->assertSame('authenticated', $response->json('tokens.status'));
        $this->freshRequest()->getJson('/api/v1/me', $this->bearer($response->json('tokens.access_token')))->assertOk();
    }

    public function test_admin_cannot_disable_mandatory_mfa(): void
    {
        $admin = $this->makeUser([], Role::ADMIN);
        $secret = $this->enableMfa($admin);
        $mfaToken = $this->login($admin)->json('mfa_token');
        $access = $this->freshRequest()->postJson('/api/v1/auth/mfa/verify', ['code' => $this->totp($secret)], $this->bearer($mfaToken))->json('access_token');

        // Reauth with password, then try to disable.
        $this->freshRequest()->postJson('/api/v1/auth/reauth', ['password' => self::PASSWORD], $this->bearer($access))->assertNoContent();
        $this->freshRequest()->deleteJson('/api/v1/auth/mfa', [], $this->bearer($access))
            ->assertStatus(403)->assertJsonPath('code', 'MFA_POLICY_REQUIRED');
    }

    public function test_pending_secret_never_replaces_an_active_one(): void
    {
        $user = $this->makeUser();
        $active = $this->enableMfa($user);
        $pending = app(Google2FA::class)->generateSecretKey(32);
        $user->forceFill(['mfa_pending_secret' => $pending])->save();

        $this->assertSame($active, $user->fresh()->mfa_secret);
    }
}
