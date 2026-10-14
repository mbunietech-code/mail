<?php

namespace Tests\Unit;

use App\Logging\RedactSensitiveData;
use App\Services\Auth\TokenService;
use PHPUnit\Framework\TestCase;

class LogRedactionTest extends TestCase
{
    public function test_sensitive_keys_are_masked_recursively(): void
    {
        $out = RedactSensitiveData::scrub([
            'user_id' => '01J',
            'password' => 'hunter2',
            'request' => ['headers' => ['authorization' => 'Bearer abc', 'accept' => 'json'], 'body' => 'Dear CEO'],
            'refresh_token' => 'mbr_abc',
        ]);

        $this->assertSame('01J', $out['user_id']);
        $this->assertSame('[REDACTED]', $out['password']);
        $this->assertSame('[REDACTED]', $out['request']['headers']['authorization']);
        $this->assertSame('json', $out['request']['headers']['accept']);
        $this->assertSame('[REDACTED]', $out['request']['body']);
        $this->assertSame('[REDACTED]', $out['refresh_token']);
    }

    public function test_tokens_inside_messages_are_masked(): void
    {
        $msg = RedactSensitiveData::scrubString('call failed Authorization: Bearer 12|mbm_abcdefghijklmnopqrstuvwxyz refresh mbr_ABCDEFGHIJKLMNOPQRSTUV {"password":"hunter2"}');

        $this->assertStringNotContainsString('mbm_abcdefghijklmnopqrstuvwxyz', $msg);
        $this->assertStringNotContainsString('mbr_ABCDEFGHIJKLMNOPQRSTUV', $msg);
        $this->assertStringNotContainsString('hunter2', $msg);
    }

    public function test_exception_messages_are_scrubbed(): void
    {
        $out = RedactSensitiveData::scrub(['exception' => new \RuntimeException('login failed password=hunter2', 0, new \LogicException('token: mbr_ABCDEFGHIJKLMNOPQRSTUV'))]);

        $encoded = json_encode($out);
        $this->assertStringNotContainsString('hunter2', $encoded);
        $this->assertStringNotContainsString('mbr_ABCDEFGHIJKLMNOPQRSTUV', $encoded);
        $this->assertSame(\RuntimeException::class, $out['exception']['class']);
    }

    public function test_ip_prefix_truncates_to_network(): void
    {
        $this->assertSame('198.51.100.0/24', TokenService::ipPrefix('198.51.100.77'));
        $this->assertSame('2001:db8:abcd::/48', TokenService::ipPrefix('2001:db8:abcd:12::1'));
        $this->assertNull(TokenService::ipPrefix(null));
    }
}
