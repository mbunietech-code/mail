<?php

namespace App\Console\Commands;

use App\Jobs\SyncAccountJob;
use App\Models\EmailAccount;
use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('mbuniemail:sync-due {--limit=200}')]
#[Description('Queue synchronisation for accounts whose next sync time has passed')]
class SyncDueAccounts extends Command
{
    public function handle(): int
    {
        $ids = EmailAccount::query()
            ->whereIn('status', [EmailAccount::STATUS_ACTIVE, EmailAccount::STATUS_SYNCING, EmailAccount::STATUS_THROTTLED, EmailAccount::STATUS_UNREACHABLE, EmailAccount::STATUS_PENDING])
            ->where(fn ($q) => $q->whereNull('next_sync_at')->orWhere('next_sync_at', '<=', now()))
            ->whereIn('user_id', User::query()->where('status', User::STATUS_ACTIVE)->select('id'))
            ->orderBy('next_sync_at')
            ->limit((int) $this->option('limit'))
            ->pluck('id');

        foreach ($ids as $id) {
            SyncAccountJob::dispatch($id);
        }

        $this->info("Queued {$ids->count()} account(s).");

        return self::SUCCESS;
    }
}
