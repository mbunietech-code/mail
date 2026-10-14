<?php

namespace App\Console\Commands;

use App\Security\KeyRing;
use App\Services\Audit\AuditLogger;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('mbuniemail:kek-rotate')]
#[Description('Create the credential key file, or add a new current KEK version')]
class RotateKek extends Command
{
    public function handle(AuditLogger $audit): int
    {
        $path = (string) config('mbuniemail.credentials.kek_path');
        if ($path === '') {
            $this->error('Set CREDENTIAL_KEK_PATH first (outside the application directory).');

            return self::FAILURE;
        }

        $version = KeyRing::rotate($path);
        $audit->record('security.kek.rotated', 'success', context: ['version' => $version]);

        $this->info("Credential KEK version {$version} is now current.");
        if ($version > 1) {
            $this->warn('Run `php artisan mbuniemail:kek-rewrap` to move existing credentials to the new key, then back up the key file (BACKUP_AND_RESTORE.md).');
        } else {
            $this->warn('Back up this key file separately from database backups. Without it, stored mailbox credentials cannot be decrypted.');
        }

        return self::SUCCESS;
    }
}
