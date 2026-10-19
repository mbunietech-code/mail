<?php

namespace Tests\Feature\Mail;

use App\Jobs\SyncAccountJob;
use App\Mailbox\Data\AttachmentInfo;
use App\Mailbox\Data\MessageEnvelope;
use App\Mailbox\Data\MessageFlags;
use App\Mailbox\ProviderError;
use App\Mailbox\ProviderErrorCode;
use App\Models\EmailAccount;
use App\Models\MessageMetadata;
use App\Models\SyncState;
use App\Services\Mail\SyncService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\MailFixtures;
use Tests\TestCase;

class SyncTest extends TestCase
{
    use MailFixtures;

    private EmailAccount $account;

    protected function setUp(): void
    {
        parent::setUp();
        $this->useFakeProvider();
        $this->account = $this->linkAccount($this->makeUser());
    }

    private function sync(): array
    {
        return app(SyncService::class)->syncAccount($this->account->refresh(), true);
    }

    public function test_initial_sync_caches_metadata_with_encrypted_subject_and_snippet(): void
    {
        $this->fake->addMessage('INBOX', $this->envelope(1, 'Quarterly results', attachments: [new AttachmentInfo('2', 'q4.pdf', 'application/pdf', 900)]));
        $this->fake->addMessage('INBOX', $this->envelope(2, 'Lunch?'));

        $this->assertSame(2, $this->sync()['new']);

        $row = MessageMetadata::query()->where('uid', 1)->sole();
        $this->assertSame('Quarterly results', $row->subject_enc);
        $this->assertTrue($row->has_attachments);
        $raw = DB::table('message_metadata')->where('id', $row->id)->first();
        $this->assertStringNotContainsString('Quarterly results', (string) $raw->subject_enc, 'subject encrypted at rest');
        $this->assertStringNotContainsString('Preview text', (string) $raw->snippet_enc);
        $this->assertSame(1, DB::table('attachment_metadata')->count());

        $account = $this->account->refresh();
        $this->assertSame('active', $account->status);
        $this->assertNotNull($account->last_synced_at);
        $this->assertTrue($account->next_sync_at->isFuture());
        $this->assertSame(2, $account->folders()->where('role', 'inbox')->value('unread_count'));
    }

    public function test_fresh_sign_in_is_polled_often_before_its_token_is_used(): void
    {
        $this->account->user->createToken('phone');
        $this->sync();
        $this->assertLessThanOrEqual(2, (int) round(now()->diffInMinutes($this->account->refresh()->next_sync_at)));
    }

    public function test_signed_out_users_are_polled_on_the_dormant_schedule(): void
    {
        $this->account->user->tokens()->delete();
        $this->sync();
        $this->assertSame(60, (int) round(now()->diffInMinutes($this->account->refresh()->next_sync_at)));
    }

    /** FR-SYNC-004 */
    public function test_repeated_sync_never_duplicates(): void
    {
        $this->fake->addMessage('INBOX', $this->envelope(1));
        $this->sync();
        $this->sync();

        // Even after losing the checkpoint, the unique keys prevent duplicates.
        SyncState::query()->update(['last_uid' => 0, 'uidvalidity' => null]);
        $this->sync();

        $this->assertSame(1, MessageMetadata::query()->count());
    }

    public function test_incremental_sync_adds_new_messages_updates_flags_and_removes_expunged(): void
    {
        $this->fake->addMessage('INBOX', $this->envelope(1));
        $this->fake->addMessage('INBOX', $this->envelope(2));
        $this->sync();

        $this->fake->addMessage('INBOX', $this->envelope(3, 'Newest'));
        $this->fake->messages['INBOX'][1] = $this->envelope(1, seen: true); // read in webmail
        unset($this->fake->messages['INBOX'][2]);                          // deleted elsewhere

        $this->assertSame(1, $this->sync()['new']);
        $this->assertSame([1, 3], MessageMetadata::query()->orderBy('uid')->pluck('uid')->all());
        $this->assertTrue(MessageMetadata::query()->where('uid', 1)->value('is_seen'));
        $this->assertSame(3, SyncState::query()->whereIn('folder_id', $this->account->folders()->where('role', 'inbox')->select('id'))->value('last_uid'));
    }

    /** FR-SYNC-003 */
    public function test_uidvalidity_change_triggers_clean_resync(): void
    {
        $this->fake->addMessage('INBOX', $this->envelope(1, 'Old copy'));
        $this->sync();

        $this->fake->uidValidity = 99;
        $this->fake->messages['INBOX'] = [];
        $this->fake->addMessage('INBOX', $this->envelope(7, 'After rebuild'));
        $this->sync();

        $this->assertSame(['After rebuild'], MessageMetadata::query()->get()->pluck('subject_enc')->all());
        $this->assertSame(99, MessageMetadata::query()->value('uidvalidity'));
    }

    public function test_replies_join_their_parents_thread_even_out_of_order(): void
    {
        // Reply arrives (and is synced) before its parent.
        $this->fake->addMessage('INBOX', $this->envelope(2, 'Re: Budget', messageId: 'reply@x', inReplyTo: 'root@x', references: ['root@x']));
        $this->sync();
        $this->fake->addMessage('INBOX', $this->envelope(3, 'Budget', messageId: 'root@x'));
        $this->fake->addMessage('INBOX', $this->envelope(4, 'Re: Budget', from: 'other@else.org', messageId: 'unrelated@y'));
        $this->sync();

        $keys = MessageMetadata::query()->pluck('thread_key', 'message_id_header');
        $this->assertSame($keys['reply@x'], $keys['root@x']);
        $this->assertNotSame($keys['root@x'], $keys['unrelated@y'], 'same subject alone does not thread');
    }

    public function test_auth_failure_stops_polling_until_the_user_fixes_the_password(): void
    {
        $this->fake->failOn('listFolders', ProviderErrorCode::AuthFailed);

        try {
            $this->sync();
            $this->fail('expected failure');
        } catch (ProviderError) {
        }

        $account = $this->account->refresh();
        $this->assertSame('auth_failed', $account->status);
        $this->assertSame('AUTH_FAILED', $account->last_error_code);
        $this->assertNull($account->next_sync_at, 'no retry loop against the provider');

        $this->artisan('mbuniemail:sync-due')->expectsOutputToContain('Queued 0');
    }

    /** FR-SYNC-005 / SEC-T50 */
    public function test_transient_failures_back_off_with_jitter(): void
    {
        $this->fake->failOn('listFolders', ProviderErrorCode::Unreachable);

        $delays = [];
        for ($i = 0; $i < 3; $i++) {
            try {
                app(SyncService::class)->syncAccount($this->account->refresh());
            } catch (ProviderError) {
            }
            $account = $this->account->refresh();
            $delays[] = now()->diffInSeconds($account->next_sync_at);
        }

        $this->assertSame('provider_unreachable', $this->account->refresh()->status);
        $this->assertSame(3, $this->account->backoff_attempts);
        foreach ($delays as $d) {
            $this->assertGreaterThanOrEqual(29, $d);
            $this->assertLessThanOrEqual(1800, $d);
        }
    }

    public function test_successful_sync_clears_error_state(): void
    {
        $this->account->forceFill(['status' => 'provider_unreachable', 'last_error_code' => 'UNREACHABLE', 'backoff_attempts' => 4])->save();
        $this->sync();

        $account = $this->account->refresh();
        $this->assertSame('active', $account->status);
        $this->assertNull($account->last_error_code);
        $this->assertSame(0, $account->backoff_attempts);
    }

    public function test_correspondents_feed_private_autocomplete(): void
    {
        $this->fake->addMessage('INBOX', $this->envelope(1, from: 'client@bank.co.tz', fromName: 'Client Bank'));
        $this->fake->addMessage('INBOX', $this->envelope(2, from: 'client@bank.co.tz', fromName: 'Client Bank'));
        $this->fake->addMessage('INBOX', $this->envelope(3, from: 'no-reply@service.example'));
        $this->sync();

        $contacts = DB::table('contacts')->where('user_id', $this->account->user_id)->get();
        $this->assertSame(['client@bank.co.tz'], $contacts->pluck('email')->all(), 'no-reply senders skipped');
        $this->assertSame(2, (int) $contacts->first()->use_count);
    }

    public function test_due_command_queues_only_due_accounts_of_active_users(): void
    {
        Queue::fake();
        $this->account->forceFill(['next_sync_at' => now()->subMinute()])->save();
        $later = $this->linkAccount($this->makeUser(), 'ceo@mt.co.tz');
        $later->forceFill(['next_sync_at' => now()->addHour()])->save();

        $this->artisan('mbuniemail:sync-due')->assertSuccessful();

        Queue::assertPushed(SyncAccountJob::class, fn ($job) => $job->accountId === $this->account->id);
        Queue::assertNotPushed(SyncAccountJob::class, fn ($job) => $job->accountId === $later->id);
    }

    public function test_flags_from_fake_are_copied_exactly(): void
    {
        $this->fake->addMessage('INBOX', $this->envelope(1));
        $this->fake->messages['INBOX'][1] = new MessageEnvelope(...[...get_object_vars($this->envelope(1)), 'flags' => new MessageFlags(true, true, true, false)]);
        $this->sync();

        $row = MessageMetadata::query()->sole();
        $this->assertTrue($row->is_seen && $row->is_flagged && $row->is_answered);
    }
}
