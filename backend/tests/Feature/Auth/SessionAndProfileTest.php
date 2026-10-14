<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Tests\TestCase;

class SessionAndProfileTest extends TestCase
{
    public function test_user_lists_own_sessions_with_current_flag(): void
    {
        $user = $this->makeUser();
        $this->tokensFor($user);
        $current = $this->freshRequest()->tokensFor($user);

        $this->freshRequest()->getJson('/api/v1/auth/sessions', $this->bearer($current['access_token']))
            ->assertOk()->assertJsonCount(2, 'data')
            ->assertJsonFragment(['id' => $current['session_id'], 'current' => true, 'platform' => 'android']);
    }

    public function test_user_can_revoke_one_of_their_sessions(): void
    {
        $user = $this->makeUser();
        $phone = $this->tokensFor($user);
        $pc = $this->freshRequest()->tokensFor($user);

        $this->freshRequest()->deleteJson('/api/v1/auth/sessions/'.$phone['session_id'], [], $this->bearer($pc['access_token']))->assertNoContent();

        $this->freshRequest()->getJson('/api/v1/me', $this->bearer($phone['access_token']))->assertStatus(401);
        $this->freshRequest()->getJson('/api/v1/me', $this->bearer($pc['access_token']))->assertOk();
    }

    /** SEC-T13 (auth subset): another user's session id is indistinguishable from a missing one. */
    public function test_user_cannot_revoke_another_users_session(): void
    {
        $alice = $this->tokensFor($this->makeUser());
        $bob = $this->freshRequest()->tokensFor($this->makeUser());

        $this->freshRequest()->deleteJson('/api/v1/auth/sessions/'.$alice['session_id'], [], $this->bearer($bob['access_token']))
            ->assertStatus(404)->assertJsonPath('code', 'NOT_FOUND');
        $this->freshRequest()->getJson('/api/v1/me', $this->bearer($alice['access_token']))->assertOk();
    }

    public function test_revoking_all_other_sessions_requires_reauth_and_keeps_current(): void
    {
        $user = $this->makeUser();
        $other = $this->tokensFor($user);
        $current = $this->freshRequest()->tokensFor($user);
        $auth = $this->bearer($current['access_token']);

        $this->freshRequest()->deleteJson('/api/v1/auth/sessions', [], $auth)->assertStatus(403)->assertJsonPath('code', 'REAUTH_REQUIRED');
        $this->freshRequest()->postJson('/api/v1/auth/reauth', ['password' => 'wrong-password'], $auth)->assertStatus(401);
        $this->freshRequest()->postJson('/api/v1/auth/reauth', ['password' => self::PASSWORD], $auth)->assertNoContent();
        $this->freshRequest()->deleteJson('/api/v1/auth/sessions', [], $auth)->assertNoContent();

        $this->freshRequest()->getJson('/api/v1/me', $this->bearer($other['access_token']))->assertStatus(401);
        $this->freshRequest()->getJson('/api/v1/me', $auth)->assertOk();
    }

    /** SEC-T12 */
    public function test_profile_update_ignores_privileged_fields(): void
    {
        $user = $this->makeUser();
        $tokens = $this->tokensFor($user);

        $this->freshRequest()->patchJson('/api/v1/me', [
            'name' => 'New Name',
            'email' => 'ceo@mt.co.tz',
            'status' => 'active',
            'roles' => ['super_admin'],
            'mfa_confirmed_at' => now()->toIso8601String(),
        ], $this->bearer($tokens['access_token']))->assertOk()->assertJsonPath('name', 'New Name');

        $fresh = $user->fresh();
        $this->assertSame($user->email, $fresh->email);
        $this->assertCount(0, $fresh->roles);
        $this->assertNull($fresh->mfa_confirmed_at);
    }

    public function test_password_change_requires_current_password_and_revokes_other_sessions(): void
    {
        $user = $this->makeUser();
        $other = $this->tokensFor($user);
        $current = $this->freshRequest()->tokensFor($user);
        $auth = $this->bearer($current['access_token']);
        $this->freshRequest()->postJson('/api/v1/auth/reauth', ['password' => self::PASSWORD], $auth);

        $new = 'Another-Strong-Phrase-81';
        $this->freshRequest()->putJson('/api/v1/me/password', ['current_password' => 'nope-nope-nope', 'password' => $new, 'password_confirmation' => $new], $auth)
            ->assertStatus(403);
        $this->freshRequest()->putJson('/api/v1/me/password', ['current_password' => self::PASSWORD, 'password' => $new, 'password_confirmation' => $new], $auth)
            ->assertNoContent();

        $this->freshRequest()->getJson('/api/v1/me', $this->bearer($other['access_token']))->assertStatus(401);
        $this->freshRequest()->getJson('/api/v1/me', $auth)->assertOk();
        $this->assertNotNull(User::query()->find($user->id)->password_changed_at);
    }
}
