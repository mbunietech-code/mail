<?php

namespace Tests\Unit\Mailbox;

use App\Mailbox\ImapSmtp\TlsPolicy;
use Tests\TestCase;

/** NFR-SEC-001: verification is always on; the dev test CA extends, never replaces, the system trust store. */
class TlsPolicyTest extends TestCase
{
    private const TEST_CA_PEM = "-----BEGIN CERTIFICATE-----\nTEST-CA-MARKER\n-----END CERTIFICATE-----\n";

    private string $testCa;

    protected function setUp(): void
    {
        parent::setUp();
        $this->testCa = tempnam(sys_get_temp_dir(), 'mbunie-ca-');
        file_put_contents($this->testCa, self::TEST_CA_PEM);
    }

    protected function tearDown(): void
    {
        @unlink($this->testCa);
        foreach (glob(storage_path('framework/mbuniemail-ca-bundle-*.pem')) ?: [] as $bundle) {
            if (str_contains((string) file_get_contents($bundle), 'TEST-CA-MARKER')) {
                @unlink($bundle);
            }
        }
        parent::tearDown();
    }

    public function test_verification_is_always_on_without_test_ca(): void
    {
        config(['mbuniemail.providers.test_ca_file' => null]);

        $ssl = TlsPolicy::streamContext('imap.hostinger.com')['ssl'];

        $this->assertTrue($ssl['verify_peer']);
        $this->assertTrue($ssl['verify_peer_name']);
        $this->assertFalse($ssl['allow_self_signed']);
        $this->assertSame('imap.hostinger.com', $ssl['peer_name']);
        $this->assertArrayNotHasKey('cafile', $ssl);
    }

    public function test_test_ca_is_added_to_the_system_bundle(): void
    {
        $system = TlsPolicy::systemBundle();
        if ($system === null) {
            $this->markTestSkipped('No system CA bundle on this host.');
        }
        config(['mbuniemail.providers.test_ca_file' => $this->testCa]);

        $ssl = TlsPolicy::streamContext('localhost')['ssl'];

        $this->assertTrue($ssl['verify_peer']);
        $this->assertTrue($ssl['verify_peer_name']);
        $this->assertNotSame($this->testCa, $ssl['cafile']);
        $bundle = file_get_contents($ssl['cafile']);
        $this->assertStringContainsString('TEST-CA-MARKER', $bundle);
        $this->assertStringStartsWith(rtrim(file_get_contents($system)), $bundle);
        // Same inputs reuse the cached bundle.
        $this->assertSame($ssl['cafile'], TlsPolicy::streamContext('localhost')['ssl']['cafile']);
    }

    public function test_combined_bundle_falls_back_to_test_ca_alone_without_system_bundle(): void
    {
        $bundle = TlsPolicy::combinedBundle($this->testCa, null);

        $this->assertSame(self::TEST_CA_PEM, file_get_contents($bundle));
    }

    public function test_test_ca_is_ignored_in_production(): void
    {
        config(['mbuniemail.providers.test_ca_file' => $this->testCa]);
        $this->app->detectEnvironment(fn () => 'production');

        $ssl = TlsPolicy::streamContext('imap.hostinger.com')['ssl'];

        $this->assertArrayNotHasKey('cafile', $ssl);
        $this->assertTrue($ssl['verify_peer']);
        $this->assertTrue($ssl['verify_peer_name']);
    }
}
