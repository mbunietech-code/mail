<?php

namespace App\Console\Commands;

use App\Models\EncryptedCredential;
use App\Security\CredentialVault;
use App\Security\KeyRing;
use App\Services\Audit\AuditLogger;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

#[Signature('mbuniemail:kek-rewrap')]
#[Description('Re-wrap all credential data keys under the current KEK version')]
class RewrapCredentials extends Command
{
    public function handle(CredentialVault $vault, KeyRing $keys, AuditLogger $audit): int
    {
        $current = $keys->currentVersion();
        $moved = 0;
        $failed = 0;

        EncryptedCredential::query()->where('kek_version', '!=', $current)->chunkById(200, function ($credentials) use ($vault, &$moved, &$failed) {
            foreach ($credentials as $credential) {
                try {
                    $moved += (int) $vault->rewrap($credential);
                } catch (Throwable $e) {
                    $failed++;
                    report($e);
                }
            }
        }, 'email_account_id');

        $audit->record('security.kek.rewrapped', $failed === 0 ? 'success' : 'failure', context: ['moved' => $moved, 'failed' => $failed, 'version' => $current]);
        $this->info("Re-wrapped {$moved} credential(s) to KEK v{$current}; {$failed} failed.");

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
