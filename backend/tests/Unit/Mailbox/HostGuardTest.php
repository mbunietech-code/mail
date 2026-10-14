<?php

namespace Tests\Unit\Mailbox;

use App\Mailbox\HostGuard;
use App\Mailbox\ProviderError;
use App\Mailbox\ProviderErrorCode;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** SEC-T18 (provider connections): internal destinations are refused. */
class HostGuardTest extends TestCase
{
    /** @param list<string> $ips */
    private function guard(array $ips): HostGuard
    {
        return new HostGuard(fn (string $host) => $ips);
    }

    public function test_public_addresses_are_allowed_and_ipv4_preferred(): void
    {
        $this->assertSame('172.65.188.64', $this->guard(['2606:4700:90::1', '172.65.188.64'])->resolve('imap.hostinger.com'));
    }

    /** @return array<string, array{string}> */
    public static function internalAddresses(): array
    {
        return [
            'rfc1918' => ['10.1.2.3'], 'rfc1918-172' => ['172.16.0.9'], 'rfc1918-192' => ['192.168.1.1'],
            'metadata' => ['169.254.169.254'], 'cgnat' => ['100.64.0.1'], 'this-net' => ['0.0.0.0'],
            'benchmark' => ['198.18.0.1'], 'multicast' => ['224.0.0.1'], 'ipv6-ula' => ['fd00::1'],
            'ipv6-link-local' => ['fe80::1'], 'ipv4-mapped-loopback' => ['::ffff:127.0.0.1'], 'ipv6-multicast' => ['ff02::1'],
        ];
    }

    #[DataProvider('internalAddresses')]
    public function test_internal_addresses_are_refused(string $ip): void
    {
        try {
            $this->guard([$ip])->resolve('mail.example.com');
            $this->fail("{$ip} was allowed");
        } catch (ProviderError $e) {
            $this->assertSame(ProviderErrorCode::HostNotAllowed, $e->errorCode);
        }
    }

    public function test_any_internal_record_among_public_ones_is_refused(): void
    {
        $this->expectException(ProviderError::class);
        $this->guard(['172.65.188.64', '10.0.0.5'])->resolve('mixed.example.com');
    }

    public function test_loopback_is_allowed_only_when_explicitly_enabled_outside_production(): void
    {
        config(['mbuniemail.providers.allow_loopback_hosts' => true]);
        $this->assertSame('127.0.0.1', $this->guard(['127.0.0.1'])->resolve('localhost'));

        config(['mbuniemail.providers.allow_loopback_hosts' => false]);
        $this->expectException(ProviderError::class);
        $this->guard(['127.0.0.1'])->resolve('localhost');
    }

    public function test_loopback_flag_is_ignored_in_production(): void
    {
        config(['mbuniemail.providers.allow_loopback_hosts' => true]);
        $this->app['env'] = 'production';

        try {
            $this->expectException(ProviderError::class);
            $this->guard(['127.0.0.1'])->resolve('localhost');
        } finally {
            $this->app['env'] = 'testing';
        }
    }

    public function test_malformed_hostnames_are_refused(): void
    {
        $this->expectException(ProviderError::class);
        $this->guard(['8.8.8.8'])->resolve("evil.com\r\nX: y");
    }
}
