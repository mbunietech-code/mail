<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Creates the Hostinger provider profile from config (environment) values. Hosts are never
 * hard-coded: if IMAP_HOST / SMTP_HOST are not configured the profile is skipped.
 */
class ProviderProfileSeeder extends Seeder
{
    public function run(): void
    {
        $this->seedGmail();

        $cfg = config('mbuniemail.providers.hostinger');

        if (! $cfg['imap_host'] || ! $cfg['smtp_host']) {
            $this->command->warn('IMAP_HOST/SMTP_HOST not set — skipping Hostinger provider profile.');

            return;
        }

        $values = [
            'label' => 'Hostinger Email',
            'driver' => 'imap_smtp',
            'imap_host' => $cfg['imap_host'],
            'imap_port' => $cfg['imap_port'],
            'imap_encryption' => $cfg['imap_encryption'],
            'smtp_host' => $cfg['smtp_host'],
            'smtp_port' => $cfg['smtp_port'],
            'smtp_encryption' => $cfg['smtp_encryption'],
            'saves_sent_automatically' => $cfg['saves_sent_automatically'],
            'max_connections_per_mailbox' => 2,
            'enabled' => true,
            'updated_at' => now(),
        ];

        if (DB::table('provider_profiles')->where('key', 'hostinger')->exists()) {
            DB::table('provider_profiles')->where('key', 'hostinger')->update($values);
        } else {
            // Domains are seeded once; afterwards administrators manage them (admin → Domains).
            DB::table('provider_profiles')->insert($values + ['id' => (string) Str::ulid(), 'key' => 'hostinger', 'domains' => json_encode(config('mbuniemail.allowed_domains')), 'created_at' => now()]);
        }
    }

    private function seedGmail(): void
    {
        $values = ['label' => 'Gmail', 'driver' => 'gmail', 'domains' => json_encode([]), 'enabled' => (bool) config('mbuniemail.gmail.enabled'), 'updated_at' => now()];

        if (DB::table('provider_profiles')->where('key', 'gmail')->exists()) {
            DB::table('provider_profiles')->where('key', 'gmail')->update($values);
        } else {
            DB::table('provider_profiles')->insert($values + ['id' => (string) Str::ulid(), 'key' => 'gmail', 'created_at' => now()]);
        }
    }
}
