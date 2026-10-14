<?php

namespace App\Console\Commands;

use App\Models\AuditLog;
use App\Services\Audit\AuditLogger;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Audit-log retention (SRS §10, PRIV-T02). Rows older than the retention period are
 * exported to a gzip JSON-lines archive (with SHA-256), then deleted, and the chain
 * anchor moves forward so `mbuniemail:audit-verify` keeps working. The archive contains
 * the hashes, so it remains verifiable on its own. Deletion uses the `audit_maint`
 * connection (the app's DB account cannot delete audit rows).
 */
#[Signature('mbuniemail:prune-audit {--dry-run}')]
#[Description('Archive and delete audit entries older than the retention period')]
class PruneAudit extends Command
{
    public function handle(AuditLogger $audit): int
    {
        $cutoff = now()->subDays((int) config('mbuniemail.audit.retention_days'));
        $lastId = AuditLog::query()->where('occurred_at', '<', $cutoff)->max('id');
        if ($lastId === null) {
            $this->info('Nothing older than the retention period.');

            return self::SUCCESS;
        }

        if (($broken = $audit->verify()) !== null) {
            $this->error("Audit chain is broken at #{$broken}; refusing to prune (INCIDENT_RESPONSE P6).");

            return self::FAILURE;
        }

        $head = DB::table('audit_chain_head')->where('id', 1)->first();
        $firstId = (int) $head->anchor_id;
        $count = AuditLog::query()->whereBetween('id', [$firstId, $lastId])->count();
        if ($this->option('dry-run')) {
            $this->info("Would archive and delete {$count} entries (#{$firstId}–#{$lastId}).");

            return self::SUCCESS;
        }

        // 1. Archive (written before anything is deleted).
        $name = sprintf('audit-archive/audit-%d-%d-%s.jsonl.gz', $firstId, $lastId, now()->format('Ymd\THis\Z'));
        Storage::disk('local')->makeDirectory('audit-archive');
        $gz = gzopen(Storage::disk('local')->path($name), 'wb9');
        if ($gz === false) {
            $this->error('Cannot write the archive.');

            return self::FAILURE;
        }
        AuditLog::query()->whereBetween('id', [$firstId, $lastId])->orderBy('id')->chunk(1000, function ($logs) use ($gz) {
            foreach ($logs as $log) {
                gzwrite($gz, json_encode([...$log->getAttributes(), 'ip' => $log->ipAddress(), 'occurred_at' => $log->occurred_at->format('Y-m-d H:i:s.u')], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n");
            }
        });
        gzclose($gz);
        $sha = hash_file('sha256', Storage::disk('local')->path($name));
        Storage::disk('local')->put($name.'.sha256', $sha.'  '.basename($name)."\n");

        // 2. Move the anchor and delete, atomically.
        $anchorPrev = (string) AuditLog::query()->whereKey($lastId)->value('hash');
        $maint = DB::connection(config('mbuniemail.audit.maint_connection'));
        $maint->transaction(function () use ($maint, $firstId, $lastId, $anchorPrev) {
            $maint->table('audit_chain_head')->where('id', 1)->update(['anchor_id' => $lastId + 1, 'anchor_prev_hash' => $anchorPrev]);
            $maint->table('audit_logs')->whereBetween('id', [$firstId, $lastId])->delete();
        });

        $audit->record('system.audit.pruned', 'success', context: ['from' => $firstId, 'to' => $lastId, 'count' => $count, 'archive_sha256' => $sha]);
        $this->info("Archived and deleted {$count} entries (#{$firstId}–#{$lastId}) → storage/app/private/{$name}. Copy it off-site.");

        return self::SUCCESS;
    }
}
