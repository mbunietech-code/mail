<?php

namespace Tests\Feature\Ops;

use App\Models\AuditLog;
use App\Models\EmailAccount;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\MailFixtures;
use Tests\TestCase;

/** PRIV-T01 / PRIV-T02 (SRS §10 retention). */
class RetentionTest extends TestCase
{
    use MailFixtures;

    /** PRIV-T01 */
    public function test_deactivated_users_are_purged_after_retention_unless_on_legal_hold(): void
    {
        $this->useFakeProvider();
        $leaver = $this->makeUser(['email' => 'leaver@mt.co.tz']);
        $this->linkAccount($leaver, 'leaver@mt.co.tz');
        $held = $this->makeUser(['email' => 'held@mt.co.tz']);
        $recent = $this->makeUser(['email' => 'recent@mt.co.tz']);
        $leaver->forceFill(['status' => 'deactivated', 'deactivated_at' => now()->subDays(31)])->save();
        $held->forceFill(['status' => 'deactivated', 'deactivated_at' => now()->subDays(31), 'legal_hold_until' => now()->addYear()])->save();
        $recent->forceFill(['status' => 'deactivated', 'deactivated_at' => now()->subDays(5)])->save();

        $this->artisan('mbuniemail:prune')->assertSuccessful();

        $this->assertNull(User::query()->find($leaver->id));
        $this->assertSame(0, EmailAccount::query()->where('user_id', $leaver->id)->count());
        $this->assertSame(0, DB::table('encrypted_credentials')->count(), 'credentials cascade-deleted');
        $this->assertNotNull(User::query()->find($held->id), 'legal hold respected');
        $this->assertNotNull(User::query()->find($recent->id), 'retention period respected');
        $this->assertTrue(AuditLog::query()->where('action', 'system.user.purged')->where('target_id', $leaver->id)->exists());
    }

    /** PRIV-T02: archive, delete, and the chain stays verifiable. */
    public function test_old_audit_entries_are_archived_then_pruned_without_breaking_the_chain(): void
    {
        Storage::fake('local');
        $audit = app(AuditLogger::class);
        for ($i = 0; $i < 5; $i++) {
            $audit->record('old.event', context: ['n' => $i]);
        }
        $this->travel(731)->days();
        $audit->record('recent.event');

        $this->artisan('mbuniemail:prune-audit --dry-run')->expectsOutputToContain('Would archive and delete 5 entries')->assertSuccessful();
        $this->assertSame(6, AuditLog::query()->count());

        $this->artisan('mbuniemail:prune-audit')->assertSuccessful();

        $this->assertSame(0, AuditLog::query()->where('action', 'old.event')->count());
        $this->assertNull($audit->verify(), 'chain verifies from the new anchor');

        $files = Storage::disk('local')->files('audit-archive');
        $archive = collect($files)->first(fn ($f) => str_ends_with($f, '.jsonl.gz'));
        $this->assertNotNull($archive);
        $lines = array_filter(explode("\n", gzdecode(Storage::disk('local')->get($archive))));
        $this->assertCount(5, $lines);
        $this->assertSame(hash('sha256', Storage::disk('local')->get($archive)), strtok(Storage::disk('local')->get($archive.'.sha256'), ' '));
        $this->assertTrue(AuditLog::query()->where('action', 'system.audit.pruned')->exists());

        // Tampering after a prune is still detected.
        DB::table('audit_logs')->where('action', 'recent.event')->update(['result' => 'failure']);
        $this->assertNotNull($audit->verify());
    }

    public function test_pruning_refuses_when_the_chain_is_already_broken(): void
    {
        Storage::fake('local');
        $audit = app(AuditLogger::class);
        $first = $audit->record('old.event');
        $audit->record('old.event');
        DB::table('audit_logs')->where('id', $first->id)->update(['action' => 'forged']);
        $this->travel(731)->days();

        $this->artisan('mbuniemail:prune-audit')->assertFailed();
        $this->assertSame(2, AuditLog::query()->count(), 'evidence preserved');
    }
}
