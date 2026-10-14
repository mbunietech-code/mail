<?php

namespace Tests\Feature\Security;

use App\Models\MessageMetadata;
use App\Models\User;
use App\Services\Mail\SyncService;
use Tests\Concerns\MailFixtures;
use Tests\TestCase;

class SecurityRegressionTest extends TestCase
{
    use MailFixtures;

    private const SQLI = [
        "' OR '1'='1",
        '1; DROP TABLE users;--',
        '" OR ""="',
        "' UNION SELECT password FROM users--",
        "1' AND SLEEP(5)--",
        "%' OR 1=1 #",
        "\\'; SELECT * FROM encrypted_credentials; --",
        "' OR updatexml(1,concat(0x7e,(SELECT version())),1)--",
    ];

    /** SEC-T15: injection payloads in every query parameter neither error nor leak. */
    public function test_sql_injection_payloads_are_inert(): void
    {
        $this->useFakeProvider();
        $user = $this->makeUser();
        $account = $this->linkAccount($user);
        $this->fake->addMessage('INBOX', $this->envelope(1, 'Only message'));
        app(SyncService::class)->syncAccount($account->refresh(), true);
        $auth = $this->bearer($this->tokensFor($user)['access_token']);
        $folder = $account->folders()->where('role', 'inbox')->firstOrFail();
        $usersBefore = User::query()->count();

        foreach (self::SQLI as $payload) {
            $q = rawurlencode($payload);
            foreach ([
                "/api/v1/accounts/{$account->id}/search?from={$q}",
                "/api/v1/accounts/{$account->id}/search?to={$q}",
                "/api/v1/contacts/suggest?q={$q}",
                "/api/v1/folders/{$folder->id}/messages?sort={$q}",
                "/api/v1/folders/{$folder->id}/messages?cursor={$q}",
                '/api/v1/messages/'.$q,
                "/api/v1/threads/{$q}?account_id={$account->id}",
            ] as $url) {
                $response = $this->freshRequest()->getJson($url, $auth);
                $this->assertLessThan(500, $response->status(), "{$url} errored");
                $this->assertStringNotContainsString('SQLSTATE', $response->getContent());
                $this->assertStringNotContainsString('$argon2', $response->getContent());
                $this->assertStringNotContainsString('$2y$', $response->getContent());
            }
            // A payload must never widen a search to more than the user's own rows.
            $this->assertLessThanOrEqual(1, count($this->freshRequest()->getJson("/api/v1/accounts/{$account->id}/search?from={$q}", $auth)->json('data') ?? []));
        }

        $this->assertSame($usersBefore, User::query()->count());
    }

    /** SEC-T30: the general API limiter applies per user, with Retry-After. */
    public function test_general_api_rate_limit(): void
    {
        config(['mbuniemail.throttle.api_per_minute' => 5]);
        $auth = $this->bearer($this->tokensFor($this->makeUser())['access_token']);

        for ($i = 0; $i < 5; $i++) {
            $this->freshRequest()->getJson('/api/v1/me', $auth)->assertOk();
        }
        $this->freshRequest()->getJson('/api/v1/me', $auth)->assertStatus(429)->assertHeader('Retry-After')->assertJsonPath('code', 'RATE_LIMITED');

        // Another user is unaffected.
        $other = $this->bearer($this->freshRequest()->tokensFor($this->makeUser())['access_token']);
        $this->freshRequest()->getJson('/api/v1/me', $other)->assertOk();
    }

    /** SEC-T47: an administrator's API token reaches no other user's mail content. */
    public function test_admin_api_tokens_have_no_content_access(): void
    {
        $this->useFakeProvider();
        $employee = $this->makeUser();
        $account = $this->linkAccount($employee);
        $this->fake->addMessage('INBOX', $this->envelope(1, 'Private'));
        app(SyncService::class)->syncAccount($account->refresh(), true);
        $message = MessageMetadata::query()->firstOrFail();

        $admin = $this->makeUser([], 'super_admin');
        $secret = $this->enableMfa($admin);
        $mfa = $this->freshRequest()->login($admin)->json('mfa_token');
        $token = $this->freshRequest()->postJson('/api/v1/auth/mfa/verify', ['code' => $this->totp($secret)], $this->bearer($mfa))->json('access_token');
        $auth = $this->bearer($token);

        foreach ([
            "/api/v1/messages/{$message->id}",
            "/api/v1/folders/{$message->folder_id}/messages",
            "/api/v1/threads/{$message->thread_key}?account_id={$account->id}",
            "/api/v1/accounts/{$account->id}/search?from=partner",
            "/api/v1/accounts/{$account->id}",
        ] as $url) {
            $this->freshRequest()->getJson($url, $auth)->assertStatus(404);
        }
    }
}
