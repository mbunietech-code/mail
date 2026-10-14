<?php

namespace Tests\Feature\Security;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use RuntimeException;
use Tests\TestCase;

class HttpHardeningTest extends TestCase
{
    /** SEC-T40 */
    public function test_api_responses_carry_security_headers_and_request_id(): void
    {
        $response = $this->postJson('/api/v1/auth/login', []);

        $response->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('X-Frame-Options', 'DENY')
            ->assertHeader('Referrer-Policy', 'no-referrer')
            ->assertHeader('Content-Security-Policy')
            ->assertHeader('X-Request-Id')
            ->assertHeaderMissing('X-Powered-By');
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $this->assertStringContainsString("default-src 'none'", (string) $response->headers->get('Content-Security-Policy'));
    }

    public function test_errors_use_problem_json_with_stable_code(): void
    {
        $this->postJson('/api/v1/auth/login', ['email' => 'not-an-email'])
            ->assertStatus(422)
            ->assertHeader('Content-Type', 'application/problem+json')
            ->assertJsonPath('code', 'VALIDATION_FAILED')
            ->assertJsonStructure(['type', 'title', 'status', 'code', 'request_id', 'errors' => ['email', 'password']]);
    }

    /** SEC-T34: unexpected exceptions never leak messages or traces, even with debug on. */
    public function test_unexpected_errors_do_not_leak_details(): void
    {
        config(['app.debug' => true]);
        Route::middleware('api')->get('api/v1/__boom', fn () => throw new RuntimeException('db password=hunter2 at /var/www/secret.php'));

        $response = $this->getJson('/api/v1/__boom')->assertStatus(500)->assertJsonPath('code', 'INTERNAL_ERROR');

        $this->assertStringNotContainsString('hunter2', $response->getContent());
        $this->assertStringNotContainsString('secret.php', $response->getContent());
        $this->assertArrayNotHasKey('trace', $response->json());
    }

    public function test_unknown_api_route_returns_problem_json_404(): void
    {
        $this->getJson('/api/v1/does-not-exist')->assertStatus(404)->assertJsonPath('code', 'NOT_FOUND');
    }

    /** SEC-T31: X-Forwarded-For from an untrusted peer cannot reset IP-based limits. */
    public function test_spoofed_forwarded_for_header_is_ignored(): void
    {
        $user = $this->makeUser();

        for ($i = 0; $i < 5; $i++) {
            $this->withHeader('X-Forwarded-For', '203.0.113.'.$i)->login($user, 'wrong-password')->assertStatus(401);
        }

        $this->withHeader('X-Forwarded-For', '203.0.113.200')->login($user)->assertStatus(429);
    }

    public function test_configured_proxy_is_trusted_for_client_ip(): void
    {
        config(['trustedproxy.proxies' => ['127.0.0.1']]);
        Route::middleware('api')->get('api/v1/__ip', fn (Request $r) => ['ip' => $r->ip()]);

        $this->withHeader('X-Forwarded-For', '203.0.113.7')->getJson('/api/v1/__ip')->assertJsonPath('ip', '203.0.113.7');
    }

    public function test_cors_does_not_allow_arbitrary_origins(): void
    {
        $this->call('OPTIONS', '/api/v1/auth/login', [], [], [], [
            'HTTP_ORIGIN' => 'https://evil.example',
            'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'POST',
        ])->assertHeaderMissing('Access-Control-Allow-Origin');
    }

    public function test_client_request_id_is_echoed_only_when_well_formed(): void
    {
        $this->withHeader('X-Request-Id', 'client-req-12345')->postJson('/api/v1/auth/login', [])
            ->assertHeader('X-Request-Id', 'client-req-12345');

        $id = $this->withHeader('X-Request-Id', "bad\nvalue")->postJson('/api/v1/auth/login', [])->headers->get('X-Request-Id');
        $this->assertMatchesRegularExpression('/^[0-9A-Z]{26}$/', (string) $id);
    }
}
