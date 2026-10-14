<?php

namespace App\Mailbox;

/**
 * Resolves a provider hostname once and refuses internal destinations (risk R-09, SSRF).
 * Callers connect to the returned IP while verifying TLS against the hostname, so a
 * later DNS change (rebinding) cannot redirect the connection.
 */
class HostGuard
{
    /** @var \Closure(string): list<string> */
    private \Closure $resolver;

    /** @param (\Closure(string): list<string>)|null $resolver */
    public function __construct(?\Closure $resolver = null)
    {
        $this->resolver = $resolver ?? self::systemResolver(...);
    }

    /**
     * @param  bool  $permitDevLoopback  provider connections may use the local/testing
     *                                   loopback exception (GreenMail); untrusted URLs
     *                                   such as remote images must pass false
     */
    public function resolve(string $host, bool $permitDevLoopback = true): string
    {
        $host = strtolower(trim($host));
        if ($host === '' || ! preg_match('/^[a-z0-9.-]+$/', $host)) {
            throw ProviderError::of(ProviderErrorCode::HostNotAllowed, 'Invalid provider hostname.');
        }

        $ips = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : ($this->resolver)($host);
        if ($ips === []) {
            throw ProviderError::of(ProviderErrorCode::Unreachable, "Could not resolve {$host}.");
        }

        foreach ($ips as $ip) {
            if (! $this->isAllowed($ip, $permitDevLoopback)) {
                throw ProviderError::of(ProviderErrorCode::HostNotAllowed, "{$host} resolves to a non-public address.");
            }
        }

        // Prefer IPv4 for broadest connectivity; all candidates were validated above.
        foreach ($ips as $ip) {
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                return $ip;
            }
        }

        return $ips[0];
    }

    public function isAllowed(string $ip, bool $permitDevLoopback = true): bool
    {
        if ($permitDevLoopback && $this->loopbackPermitted() && in_array($ip, ['127.0.0.1', '::1'], true)) {
            return true;
        }

        if (! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            return false;
        }

        // Ranges PHP's filter does not cover: CGNAT, benchmarking, IETF protocol assignments, multicast.
        $long = ip2long($ip);
        if ($long !== false) {
            foreach (['100.64.0.0/10', '198.18.0.0/15', '192.0.0.0/24', '224.0.0.0/4'] as $cidr) {
                [$net, $bits] = explode('/', $cidr);
                $mask = -1 << (32 - (int) $bits);
                if (($long & $mask) === (ip2long($net) & $mask)) {
                    return false;
                }
            }
        } elseif (str_starts_with(strtolower($ip), 'ff')) {
            return false; // IPv6 multicast
        }

        return true;
    }

    private function loopbackPermitted(): bool
    {
        return (bool) config('mbuniemail.providers.allow_loopback_hosts') && app()->environment('local', 'testing');
    }

    /** @return list<string> */
    private static function systemResolver(string $host): array
    {
        $ips = gethostbynamel($host) ?: [];
        foreach (@dns_get_record($host, DNS_AAAA) ?: [] as $record) {
            if (isset($record['ipv6'])) {
                $ips[] = $record['ipv6'];
            }
        }

        return array_values(array_unique($ips));
    }
}
