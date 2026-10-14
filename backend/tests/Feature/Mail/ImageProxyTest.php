<?php

namespace Tests\Feature\Mail;

use App\Mailbox\HostGuard;
use App\Security\ImageProxySigner;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** SEC-T18 / SEC-T19 */
class ImageProxyTest extends TestCase
{
    /** @var array<string, string> */
    private array $auth;

    private const PNG = "\x89PNG\r\n\x1a\n\x00\x00\x00\rIHDR\x00\x00\x00\x01\x00\x00\x00\x01\x08\x06\x00\x00\x00\x1f\x15\xc4\x89\x00\x00\x00\rIDATx\x9cc\xf8\x0f\x00\x00\x01\x01\x00\x05\x18\xd8N\x00\x00\x00\x00IEND\xaeB`\x82";

    protected function setUp(): void
    {
        parent::setUp();
        $this->auth = $this->bearer($this->tokensFor($this->makeUser())['access_token']);

        // Deterministic DNS for the test: public hosts and one internal one.
        $this->app->instance(HostGuard::class, new HostGuard(fn (string $host) => match ($host) {
            'cdn.example' => ['93.184.216.34'],
            'redirector.example' => ['93.184.216.35'],
            'internal.example' => ['10.0.0.8'],
            'metadata.example' => ['169.254.169.254'],
            default => [],
        }));
    }

    private function proxyUrl(string $target, ?int $exp = null): string
    {
        $signed = app(ImageProxySigner::class)->sign($target, $exp);

        return '/api/v1/image-proxy?'.parse_url($signed, PHP_URL_QUERY);
    }

    public function test_signed_public_image_is_served_with_safe_headers(): void
    {
        $this->resetHttpFakes(['cdn.example/*' => Http::response(self::PNG, 200, ['Content-Type' => 'text/html'])]);

        $response = $this->get($this->proxyUrl('https://cdn.example/logo.png'), $this->auth)->assertOk();

        $this->assertSame('image/png', $response->headers->get('Content-Type'), 'type is sniffed, not trusted from the remote header');
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        $this->assertStringContainsString('sandbox', (string) $response->headers->get('Content-Security-Policy'));
        Http::assertSent(fn ($r) => $r->hasHeader('User-Agent', 'MbunieMail-ImageProxy/1.0') && ! $r->hasHeader('Cookie') && ! $r->hasHeader('Authorization'));
    }

    public function test_unsigned_tampered_and_expired_links_are_refused(): void
    {
        $this->resetHttpFakes(['*' => Http::response(self::PNG)]);
        $valid = $this->proxyUrl('https://cdn.example/logo.png');
        parse_str((string) parse_url($valid, PHP_URL_QUERY), $q);

        $this->get('/api/v1/image-proxy?u='.rawurlencode('https://cdn.example/logo.png').'&exp='.(time() + 60).'&sig='.str_repeat('0', 64), $this->auth)->assertStatus(403)->assertJsonPath('code', 'INVALID_SIGNATURE');
        $this->freshRequest()->get('/api/v1/image-proxy?'.http_build_query([...$q, 'u' => 'https://internal.example/admin']), $this->auth)->assertStatus(403);
        $this->freshRequest()->get($this->proxyUrl('https://cdn.example/logo.png', now()->subMinute()->getTimestamp()), $this->auth)->assertStatus(403);
        $this->freshRequest()->get($valid)->assertStatus(401);
        Http::assertNothingSent();
    }

    public function test_internal_destinations_are_never_fetched_even_when_signed(): void
    {
        $this->resetHttpFakes(['*' => Http::response(self::PNG)]);

        foreach (['https://internal.example/x.png', 'http://metadata.example/latest/meta-data', 'http://127.0.0.1/x.png', 'http://[::1]/x.png', 'https://cdn.example:8443/x.png', 'file:///etc/passwd', 'https://user:pw@cdn.example/x.png'] as $target) {
            $this->freshRequest()->get($this->proxyUrl($target), $this->auth)->assertStatus(502);
        }
        Http::assertNothingSent();
    }

    public function test_redirects_to_internal_hosts_are_blocked(): void
    {
        $this->resetHttpFakes([
            'redirector.example/*' => Http::response('', 302, ['Location' => 'http://metadata.example/latest/meta-data']),
            '*' => Http::response('SECRET'),
        ]);

        $this->get($this->proxyUrl('https://redirector.example/x.png'), $this->auth)->assertStatus(502);
        Http::assertSentCount(1);
    }

    public function test_non_images_and_oversized_responses_are_refused(): void
    {
        $this->resetHttpFakes([
            'cdn.example/page' => Http::response('<html><script>alert(1)</script></html>', 200, ['Content-Type' => 'image/png']),
            'cdn.example/vector' => Http::response('<svg xmlns="http://www.w3.org/2000/svg" onload="alert(1)"/>', 200, ['Content-Type' => 'image/svg+xml']),
            'cdn.example/huge' => Http::response(self::PNG.str_repeat("\0", 6 * 1024 * 1024)),
        ]);

        foreach (['page', 'vector', 'huge'] as $path) {
            $this->freshRequest()->get($this->proxyUrl("https://cdn.example/{$path}"), $this->auth)->assertStatus(502)->assertJsonPath('code', 'IMAGE_UNAVAILABLE');
        }
    }
}
