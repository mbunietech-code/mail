<?php

namespace Tests\Unit\Security;

use App\Models\EmailAccount;
use App\Models\EncryptedCredential;
use App\Models\ProviderProfile;
use App\Security\CredentialVault;
use App\Security\KeyRing;
use App\Security\Secret;
use Illuminate\Support\Str;
use LogicException;
use RuntimeException;
use Tests\TestCase;

class CredentialVaultTest extends TestCase
{
    private string $path;

    protected function setUp(): void
    {
        parent::setUp();
        $this->path = sys_get_temp_dir().'/mbm-vault-'.Str::random(8).'.json';
        KeyRing::rotate($this->path);
    }

    protected function tearDown(): void
    {
        @unlink($this->path);
        parent::tearDown();
    }

    private function vault(): CredentialVault
    {
        return new CredentialVault(new KeyRing($this->path));
    }

    /** @param array<string, mixed> $sealed */
    private function row(string $accountId, array $sealed): EncryptedCredential
    {
        $row = new EncryptedCredential;
        $row->forceFill(['email_account_id' => $accountId, 'kind' => 'password', ...$sealed]);

        return $row;
    }

    public function test_round_trip_wraps_strings_in_secret(): void
    {
        $id = (string) Str::ulid();
        $sealed = $this->vault()->seal($id, ['password' => 'mailbox-pass-123', 'expires_at' => 42]);

        $this->assertStringNotContainsString('mailbox-pass-123', $sealed['ciphertext']);
        $opened = $this->vault()->open($this->row($id, $sealed));

        $this->assertInstanceOf(Secret::class, $opened['password']);
        $this->assertSame('mailbox-pass-123', $opened['password']->reveal());
        $this->assertSame(42, $opened['expires_at']);
    }

    /** SEC-T24: a ciphertext moved to another account does not decrypt. */
    public function test_ciphertext_is_bound_to_its_account(): void
    {
        $sealed = $this->vault()->seal((string) Str::ulid(), ['password' => 'x']);

        $this->expectException(RuntimeException::class);
        $this->vault()->open($this->row((string) Str::ulid(), $sealed));
    }

    public function test_tampered_ciphertext_fails_integrity_check(): void
    {
        $id = (string) Str::ulid();
        $sealed = $this->vault()->seal($id, ['password' => 'x']);
        $sealed['ciphertext'][0] = chr(ord($sealed['ciphertext'][0]) ^ 1);

        $this->expectException(RuntimeException::class);
        $this->vault()->open($this->row($id, $sealed));
    }

    public function test_rotation_and_rewrap_keep_credentials_readable(): void
    {
        $account = new EmailAccount;
        $account->forceFill([
            'user_id' => $this->makeUser()->id,
            'provider_profile_id' => ProviderProfile::query()->where('key', 'gmail')->value('id'),
            'email_address' => 'vault@mt.co.tz',
            'status' => 'active',
        ])->save();

        $row = new EncryptedCredential;
        $row->forceFill(['email_account_id' => $account->id, 'kind' => 'password', ...$this->vault()->seal($account->id, ['password' => 'before-rotation'])])->save();
        $this->assertSame(1, $row->kek_version);

        $this->assertSame(2, KeyRing::rotate($this->path));
        $vault = $this->vault();
        $this->assertSame('before-rotation', $vault->open($row)['password']->reveal(), 'old KEK still readable');

        $this->assertTrue($vault->rewrap($row));
        $fresh = EncryptedCredential::query()->findOrFail($account->id);
        $this->assertSame(2, $fresh->kek_version);
        $this->assertSame('before-rotation', $vault->open($fresh)['password']->reveal());
        $this->assertFalse($vault->rewrap($fresh), 'already current');
    }

    public function test_key_file_with_open_permissions_is_refused(): void
    {
        if (DIRECTORY_SEPARATOR !== '/') {
            $this->markTestSkipped('POSIX permissions only');
        }
        chmod($this->path, 0644);

        $this->expectExceptionMessage('permissions are too open');
        (new KeyRing($this->path))->currentVersion();
    }

    public function test_missing_key_file_fails_closed(): void
    {
        $this->expectExceptionMessage('Credential key file not found');
        (new KeyRing('/nonexistent/kek.json'))->currentVersion();
    }

    public function test_secret_does_not_leak_through_common_channels(): void
    {
        $secret = new Secret('hunter2-secret');

        $this->assertSame('[secret]', (string) $secret);
        $this->assertSame('"[secret]"', json_encode($secret));
        $this->assertStringNotContainsString('hunter2-secret', print_r($secret, true));
        $this->assertStringNotContainsString('hunter2-secret', var_export(['s' => (string) $secret], true));

        $this->expectException(LogicException::class);
        serialize($secret);
    }
}
