<?php

namespace App\Services\Mail;

use App\Jobs\PushNewMailJob;
use App\Mailbox\Contracts\MailProvider;
use App\Mailbox\Data\SyncCursor;
use App\Mailbox\ProviderError;
use App\Mailbox\ProviderErrorCode;
use App\Mailbox\ProviderFactory;
use App\Models\EmailAccount;
use App\Models\Folder;
use App\Models\MessageMetadata;
use App\Models\PersonalAccessToken;
use App\Models\SyncState;
use App\Models\User;
use App\Services\Accounts\AccountService;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;

/**
 * Incremental metadata synchronisation for one account (SDD §8.1).
 *
 * Checkpoints are persisted per folder in the same transaction as the data they cover,
 * so a crash or restart resumes exactly where it stopped (FR-SYNC-006). Failures are
 * classified: authentication problems stop scheduling until the user fixes them;
 * transient problems back off exponentially with full jitter (FR-SYNC-005).
 */
class SyncService
{
    public function __construct(
        private readonly ProviderFactory $providers,
        private readonly AccountService $accounts,
        private readonly MetadataWriter $writer,
        private readonly ContactCollector $contacts,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @return array{new: int, folders: int}
     */
    public function syncAccount(EmailAccount $account, bool $allFolders = false): array
    {
        if (in_array($account->status, [EmailAccount::STATUS_DISABLED, EmailAccount::STATUS_AUTH_FAILED], true) && ! $allFolders) {
            return ['new' => 0, 'folders' => 0];
        }

        $account->forceFill(['status' => EmailAccount::STATUS_SYNCING])->save();
        $provider = null;

        try {
            $provider = $this->providers->forAccount($account);
            $this->accounts->syncFolders($account, $provider->listFolders());

            $new = 0;
            $synced = 0;
            foreach ($this->foldersDue($account, $allFolders) as $folder) {
                $new += $this->syncFolder($account, $folder, $provider);
                $synced++;
            }

            $account->forceFill([
                'status' => EmailAccount::STATUS_ACTIVE,
                'last_error_code' => null,
                'backoff_attempts' => 0,
                'last_synced_at' => now(),
                'next_sync_at' => now()->addMinutes($this->intervalMinutes($account)),
            ])->save();

            return ['new' => $new, 'folders' => $synced];
        } catch (ProviderError $e) {
            $this->recordFailure($account, $e);

            throw $e;
        } finally {
            $provider?->disconnect();
        }
    }

    public function syncFolder(EmailAccount $account, Folder $folder, MailProvider $provider): int
    {
        $state = SyncState::query()->find($folder->id) ?? tap(new SyncState, fn (SyncState $s) => $s->forceFill(['folder_id' => $folder->id, 'last_uid' => 0])->save());
        $cfg = config('mbuniemail.sync');
        $windowStart = now()->subDays((int) $cfg['window_days']);
        $isImap = $account->providerProfile->driver === 'imap_smtp';
        $created = 0;
        $newUnseenIds = [];

        $cursor = new SyncCursor($state->uidvalidity, $state->last_uid, $state->highest_modseq, $state->history_id);
        $wasInitial = $cursor->isInitial();
        $knownUids = $isImap && ! $wasInitial
            ? MessageMetadata::query()->where('folder_id', $folder->id)->where('uidvalidity', (int) $state->uidvalidity)->pluck('uid')->map(fn ($u) => (int) $u)->all()
            : null;

        for ($batch = 0; $batch < (int) $cfg['max_batches_per_run']; $batch++) {
            $limit = $cursor->isInitial() ? (int) $cfg['initial_limit'] : (int) $cfg['batch_size'];
            $changes = $provider->fetchChanges($folder->remote_name, $cursor, $limit, $windowStart, $batch === 0 ? $knownUids : null);

            if ($changes->uidValidityChanged) {
                // Mailbox was rebuilt on the server: cached UIDs are meaningless (FR-SYNC-003).
                DB::transaction(function () use ($folder, $state) {
                    MessageMetadata::query()->where('folder_id', $folder->id)->delete();
                    $state->forceFill(['uidvalidity' => null, 'last_uid' => 0, 'highest_modseq' => null, 'history_id' => null])->save();
                });
                $cursor = new SyncCursor;
                $knownUids = null;
                $wasInitial = true; // a rebuilt mailbox re-downloads old mail; don't push it

                continue;
            }

            $createdRows = [];
            DB::transaction(function () use ($account, $folder, $state, $changes, $knownUids, $batch, &$createdRows) {
                $uidValidity = $changes->status->uidValidity;

                foreach ($changes->newMessages as $envelope) {
                    if ($row = $this->writer->upsert($account, $folder, $uidValidity, $envelope)) {
                        $createdRows[] = $row;
                    }
                }
                foreach ($changes->flagUpdates as $uid => $flags) {
                    $this->writer->applyFlags($folder, $uid, $flags);
                }
                if ($batch === 0 && $knownUids !== null && $changes->presentUids !== null) {
                    $vanished = array_diff($knownUids, $changes->presentUids);
                    if ($vanished !== []) {
                        MessageMetadata::query()->where('folder_id', $folder->id)->whereIn('uid', $vanished)->delete();
                    }
                }

                $next = $changes->nextCursor;
                $state->forceFill([
                    'uidvalidity' => $next->uidValidity,
                    'last_uid' => $next->lastUid,
                    'highest_modseq' => $next->highestModSeq,
                    'history_id' => $next->historyId,
                    'last_incremental_at' => now(),
                    'last_full_sync_at' => $state->last_full_sync_at ?? now(),
                    'last_error_code' => null,
                    'attempts' => 0,
                ])->save();

                $folder->forceFill([
                    'total_count' => $changes->status->messages,
                    'unread_count' => $changes->status->unseen,
                ])->save();
            });

            $created += count($createdRows);
            foreach ($createdRows as $row) {
                if (! $row->is_seen) {
                    $newUnseenIds[] = $row->id;
                }
            }
            $this->contacts->collect($account, $folder, $createdRows);
            $cursor = $changes->nextCursor;

            if (! $changes->hasMore) {
                break;
            }
        }

        // Push only for mail that arrived after the first sync, never for the backlog.
        if ($folder->role === 'inbox' && ! $wasInitial && $newUnseenIds !== []) {
            PushNewMailJob::dispatch($account->id, $newUnseenIds);
        }

        return $created;
    }

    /** @return list<Folder> inbox always; other folders on their slower schedule */
    private function foldersDue(EmailAccount $account, bool $all): array
    {
        $interval = (int) config('mbuniemail.sync.other_folders_interval_minutes');
        $priority = array_flip(['inbox', 'sent', 'drafts', 'archive', 'spam', 'trash', 'custom']);

        return $account->folders()->where('selectable', true)->with('syncState')->get()
            ->filter(fn (Folder $f) => $all || $f->role === 'inbox'
                || $f->syncState === null
                || $f->syncState->last_incremental_at === null
                || $f->syncState->last_incremental_at->lt(now()->subMinutes($interval)))
            ->sortBy(fn (Folder $f) => $priority[$f->role] ?? 9)
            ->values()
            ->all();
    }

    /** Active users sync every 2 minutes; idle and dormant accounts less often (SYNC-R2). */
    private function intervalMinutes(EmailAccount $account): int
    {
        $cfg = config('mbuniemail.sync');
        $lastUse = PersonalAccessToken::query()
            ->where('tokenable_type', (new User)->getMorphClass())
            ->where('tokenable_id', $account->user_id)
            ->max('last_used_at');

        return match (true) {
            $lastUse !== null && now()->subDays(7)->lt($lastUse) => (int) $cfg['inbox_interval_minutes'],
            $lastUse !== null && now()->subDays(30)->lt($lastUse) => (int) $cfg['idle_interval_minutes'],
            default => (int) $cfg['dormant_interval_minutes'],
        };
    }

    private function recordFailure(EmailAccount $account, ProviderError $e): void
    {
        $attempts = $account->backoff_attempts + 1;

        [$status, $next] = match ($e->errorCode) {
            // Needs the user: stop polling the provider with a bad credential.
            ProviderErrorCode::AuthFailed => [EmailAccount::STATUS_AUTH_FAILED, null],
            ProviderErrorCode::Throttled => [EmailAccount::STATUS_THROTTLED, now()->addSeconds(max($e->retryAfterSeconds ?? 0, self::backoffSeconds($attempts)))],
            default => [EmailAccount::STATUS_UNREACHABLE, now()->addSeconds(self::backoffSeconds($attempts))],
        };

        $account->forceFill([
            'status' => $status,
            'last_error_code' => $e->errorCode->value,
            'backoff_attempts' => min($attempts, 255),
            'next_sync_at' => $next,
        ])->save();

        if ($e->errorCode === ProviderErrorCode::AuthFailed) {
            $this->audit->record('account.sync.auth_failed', 'failure', $account->user, 'email_account', $account->id);
        }
    }

    /**
     * Exponential backoff with jitter: random(base, min(cap, base * 2^attempt)). The
     * base floor prevents immediate retries; jitter spreads retries across accounts.
     */
    public static function backoffSeconds(int $attempt): int
    {
        $cfg = config('mbuniemail.sync');
        $ceiling = min((int) $cfg['backoff_cap_seconds'], (int) $cfg['backoff_base_seconds'] * (2 ** min($attempt, 16)));

        return random_int((int) $cfg['backoff_base_seconds'], max((int) $cfg['backoff_base_seconds'], $ceiling));
    }
}
