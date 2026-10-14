<?php

namespace Tests\Feature\Auth;

use App\Models\AuditLog;
use App\Models\RefreshSession;
use Tests\TestCase;

class RefreshTokenTest extends TestCase
{
    public function test_refresh_rotates_tokens_and_invalidates_the_previous_access_token(): void
    {
        $tokens = $this->tokensFor($this->makeUser());

        $new = $this->postJson('/api/v1/auth/refresh', ['refresh_token' => $tokens['refresh_token']])->assertOk();

        $this->assertNotSame($tokens['refresh_token'], $new->json('refresh_token'));
        $this->assertSame($tokens['session_id'], $new->json('session_id'), 'Rotation keeps the session family');
        $this->freshRequest()->getJson('/api/v1/me', $this->bearer($tokens['access_token']))->assertStatus(401);
        $this->freshRequest()->getJson('/api/v1/me', $this->bearer($new->json('access_token')))->assertOk();
    }

    /** SEC-T07 */
    public function test_reusing_a_rotated_refresh_token_revokes_the_whole_family(): void
    {
        $tokens = $this->tokensFor($this->makeUser());
        $new = $this->postJson('/api/v1/auth/refresh', ['refresh_token' => $tokens['refresh_token']])->assertOk();

        // Attacker replays the stolen, already-rotated token.
        $this->freshRequest()->postJson('/api/v1/auth/refresh', ['refresh_token' => $tokens['refresh_token']])
            ->assertStatus(401)->assertJsonPath('code', 'SESSION_REVOKED');

        // The legitimate holder's newer tokens are revoked too.
        $this->freshRequest()->postJson('/api/v1/auth/refresh', ['refresh_token' => $new->json('refresh_token')])->assertStatus(401);
        $this->freshRequest()->getJson('/api/v1/me', $this->bearer($new->json('access_token')))->assertStatus(401);
        $this->assertSame(1, AuditLog::query()->where('action', 'security.refresh_reuse')->count());
    }

    /** SEC-T08 */
    public function test_expired_access_token_is_rejected(): void
    {
        $tokens = $this->tokensFor($this->makeUser());

        $this->travel(16)->minutes();

        $this->freshRequest()->getJson('/api/v1/me', $this->bearer($tokens['access_token']))
            ->assertStatus(401)->assertJsonPath('code', 'UNAUTHENTICATED');
    }

    public function test_refresh_token_expires_when_idle(): void
    {
        $tokens = $this->tokensFor($this->makeUser());

        $this->travel(8)->days();

        $this->postJson('/api/v1/auth/refresh', ['refresh_token' => $tokens['refresh_token']])->assertStatus(401);
    }

    public function test_refresh_tokens_are_stored_only_as_hashes(): void
    {
        $tokens = $this->tokensFor($this->makeUser());

        $session = RefreshSession::query()->sole();
        $this->assertSame(hash('sha256', $tokens['refresh_token']), $session->token_hash);
        $this->assertStringNotContainsString($tokens['refresh_token'], json_encode($session->getAttributes()));
    }

    public function test_unknown_refresh_token_is_rejected(): void
    {
        $this->postJson('/api/v1/auth/refresh', ['refresh_token' => 'mbr_'.str_repeat('x', 64)])->assertStatus(401);
    }
}
