<?php

namespace App\Console\Commands;

use App\Models\OutboxAttachment;
use App\Models\OutboxMessage;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Retention housekeeping (SRS §10, DATABASE_DESIGN §6). Runs hourly.
 */
#[Signature('mbuniemail:prune')]
#[Description('Apply retention rules to outbox payloads, uploads, OAuth states and sessions')]
class PruneData extends Command
{
    public function handle(): int
    {
        $cfg = config('mbuniemail.outbox');

        // A worker that died mid-send leaves "sending": the outcome is unknown, so it must
        // never silently return to "queued" (that could deliver twice).
        $stale = OutboxMessage::query()->where('status', OutboxMessage::STATUS_SENDING)->where('claimed_at', '<', now()->subMinutes(15))
            ->update(['status' => OutboxMessage::STATUS_UNKNOWN, 'last_error_code' => 'WORKER_INTERRUPTED']);

        // Delivered content is not kept: payload and files 24 h after sending.
        $sent = OutboxMessage::query()->where('status', OutboxMessage::STATUS_SENT)->where('sent_at', '<', now()->subHours((int) $cfg['payload_retention_hours']))->whereNotNull('payload_enc');
        $sentCount = $this->purgeContent($sent);

        // Failed/unknown kept a week so the user can review and resend.
        $old = OutboxMessage::query()->whereIn('status', [OutboxMessage::STATUS_FAILED, OutboxMessage::STATUS_UNKNOWN])->where('updated_at', '<', now()->subDays((int) $cfg['failed_retention_days']))->whereNotNull('payload_enc');
        $oldCount = $this->purgeContent($old);

        // Uploads never attached to a message.
        $orphans = 0;
        foreach (OutboxAttachment::query()->whereNull('outbox_message_id')->where('created_at', '<', now()->subDay())->get() as $a) {
            Storage::disk('local')->delete($a->storage_path);
            $a->delete();
            $orphans++;
        }

        // Deactivated employees: personal data deleted after the retention period unless
        // a legal hold is set (SRS §10). Cascades remove accounts, credentials, cached
        // metadata, outbox, sessions, contacts and preferences. Audit rows keep only the id.
        $purged = 0;
        $users = User::query()->where('status', User::STATUS_DEACTIVATED)
            ->where('deactivated_at', '<', now()->subDays((int) config('mbuniemail.users.deactivated_retention_days')))
            ->where(fn ($q) => $q->whereNull('legal_hold_until')->orWhere('legal_hold_until', '<', now()))
            ->limit(100)->get();
        foreach ($users as $user) {
            foreach (OutboxAttachment::query()->where('user_id', $user->id)->get() as $a) {
                Storage::disk('local')->delete($a->storage_path);
            }
            $id = $user->id;
            $user->delete();
            app(AuditLogger::class)->record('system.user.purged', 'success', null, 'user', $id);
            $purged++;
        }

        $oauth = DB::table('oauth_states')->where('expires_at', '<', now()->subHour())->delete();
        $sessions = DB::table('refresh_sessions')
            ->where(fn ($q) => $q->where('expires_at', '<', now()->subDays(30))->orWhere('revoked_at', '<', now()->subDays(30))->orWhere('rotated_at', '<', now()->subDays(30)))
            ->delete();

        $this->info("users purged: {$purged}; stale sending→unknown: {$stale}; sent payloads purged: {$sentCount}; failed purged: {$oldCount}; orphan uploads: {$orphans}; oauth states: {$oauth}; sessions: {$sessions}");

        return self::SUCCESS;
    }

    /** @param Builder<OutboxMessage> $query */
    private function purgeContent($query): int
    {
        $count = 0;
        foreach ($query->limit(1000)->get() as $message) {
            foreach (OutboxAttachment::query()->where('outbox_message_id', $message->id)->get() as $a) {
                Storage::disk('local')->delete($a->storage_path);
                $a->delete();
            }
            $message->forceFill(['payload_enc' => null])->save();
            $count++;
        }

        return $count;
    }
}
