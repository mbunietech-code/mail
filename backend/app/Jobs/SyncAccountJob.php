<?php

namespace App\Jobs;

use App\Mailbox\ProviderError;
use App\Models\EmailAccount;
use App\Services\Mail\SyncService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;

/**
 * Synchronises one account. The payload carries only the account id — never
 * credentials or content (risk R-23). Unique per account, and never overlapping,
 * so a mailbox is only ever processed by one worker at a time (SYNC-R3).
 */
class SyncAccountJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1; // retries are scheduled through next_sync_at backoff instead

    public int $timeout = 300;

    public int $uniqueFor = 600;

    public function __construct(public readonly string $accountId, public readonly bool $allFolders = false)
    {
        $this->onQueue('sync');
    }

    public function uniqueId(): string
    {
        return $this->accountId;
    }

    /** @return list<object> */
    public function middleware(): array
    {
        return [(new WithoutOverlapping('sync:'.$this->accountId))->releaseAfter(60)->expireAfter(600)];
    }

    public function handle(SyncService $sync): void
    {
        $account = EmailAccount::query()->with('providerProfile')->find($this->accountId);
        if ($account === null || ! $account->hasAllowedDomain() || ! $account->user?->isActive()) {
            return;
        }

        try {
            $sync->syncAccount($account, $this->allFolders);
        } catch (ProviderError) {
            // Classified and recorded on the account (status, backoff); nothing to retry here.
        }
    }
}
