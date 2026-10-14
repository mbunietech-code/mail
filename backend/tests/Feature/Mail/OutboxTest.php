<?php

namespace Tests\Feature\Mail;

use App\Jobs\SendMessageJob;
use App\Mailbox\MimeComposer;
use App\Mailbox\ProviderErrorCode;
use App\Mailbox\ProviderFactory;
use App\Models\AuditLog;
use App\Models\EmailAccount;
use App\Models\MessageMetadata;
use App\Models\OutboxMessage;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Mail\SyncService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\MailFixtures;
use Tests\TestCase;

class OutboxTest extends TestCase
{
    use MailFixtures;

    private User $user;

    private EmailAccount $account;

    /** @var array<string, string> */
    private array $auth;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->useFakeProvider();
        $this->user = $this->makeUser(['name' => 'Developer One']);
        $this->account = $this->linkAccount($this->user);
        $this->auth = $this->bearer($this->tokensFor($this->user)['access_token']);
    }

    /** @param array<string, mixed> $overrides */
    private function send(array $overrides = [], ?string $key = null): TestResponse
    {
        return $this->freshRequest()->postJson('/api/v1/outbox/send', [
            'account_id' => $this->account->id,
            'to' => ['partner@example.org'],
            'cc' => ['colleague@mt.co.tz'],
            'bcc' => ['hidden@example.org'],
            'subject' => 'Proposal',
            'html' => '<p>Please review</p>',
            ...$overrides,
        ], [...$this->auth, 'Idempotency-Key' => $key ?? (string) Str::ulid()]);
    }

    private function submittedRaw(int $i = 0): string
    {
        return $this->fake->submitted[$i]['rfc822'];
    }

    public function test_send_delivers_once_from_the_account_address_and_copies_to_sent(): void
    {
        $response = $this->send()->assertStatus(202)->assertJsonStructure(['id', 'status', 'message_id']);

        $this->assertSame('sent', OutboxMessage::query()->findOrFail($response->json('id'))->status, 'sync queue runs the job inline');
        $this->assertCount(1, $this->fake->submitted);

        $sent = $this->fake->submitted[0]['message'];
        $this->assertSame('developer@mt.co.tz', $sent->from->email);
        $this->assertEqualsCanonicalizing(['partner@example.org', 'colleague@mt.co.tz', 'hidden@example.org'], array_map(fn ($a) => $a->email, $sent->allRecipients()));

        [$headers] = explode("\r\n\r\n", $this->submittedRaw(), 2);
        $this->assertStringContainsString('From: Developer One <developer@mt.co.tz>', $headers);
        $this->assertStringNotContainsString('hidden@example.org', $this->submittedRaw(), 'BCC is envelope-only');

        $this->assertCount(1, $this->fake->appended['Sent'] ?? [], 'Hostinger profile does not auto-save Sent');
        $this->assertTrue(AuditLog::query()->where('action', 'mail.send.sent')->exists());
        $this->assertStringNotContainsString('Proposal', json_encode(AuditLog::query()->pluck('context')), 'no content in audit');
    }

    /** SEC-T46 */
    public function test_idempotency_key_replay_returns_the_same_message_without_resending(): void
    {
        $key = 'client-key-'.Str::random(10);
        $first = $this->send(key: $key)->assertStatus(202);
        $second = $this->send(key: $key)->assertStatus(200);

        $this->assertSame($first->json('id'), $second->json('id'));
        $this->assertCount(1, $this->fake->submitted);

        $this->send(['subject' => 'Different'], $key)->assertStatus(409)->assertJsonPath('code', 'IDEMPOTENCY_MISMATCH');
        $this->freshRequest()->postJson('/api/v1/outbox/send', ['account_id' => $this->account->id, 'to' => ['a@b.org']], $this->auth)
            ->assertStatus(400)->assertJsonPath('code', 'IDEMPOTENCY_KEY_REQUIRED');
    }

    /** SEC-T23 */
    public function test_sender_address_cannot_be_overridden(): void
    {
        $this->send(['from' => 'ceo@mt.co.tz', 'headers' => ['From' => 'ceo@mt.co.tz']])->assertStatus(202);

        $this->assertSame('developer@mt.co.tz', $this->fake->submitted[0]['message']->from->email);
        $this->assertStringNotContainsString('ceo@mt.co.tz', $this->submittedRaw());
    }

    /** SEC-T45 */
    public function test_unknown_outcome_is_never_retried_automatically(): void
    {
        $this->fake->failOn('submit', ProviderErrorCode::UnknownOutcome);
        $id = $this->send()->json('id');

        $message = OutboxMessage::query()->findOrFail($id);
        $this->assertSame('unknown', $message->status);
        $this->assertSame(1, $message->attempts);
        (new SendMessageJob($id))->handle(app(ProviderFactory::class), app(MimeComposer::class), app(AuditLogger::class));
        $this->assertSame(1, $message->refresh()->attempts, 'a stray job run must not resubmit');

        // The user decides: confirmation is mandatory because it may already be delivered.
        $this->freshRequest()->postJson("/api/v1/outbox/{$id}/resend", [], $this->auth)->assertStatus(422)->assertJsonPath('code', 'CONFIRMATION_REQUIRED');
        unset($this->fake->failures['submit']);
        $this->freshRequest()->postJson("/api/v1/outbox/{$id}/resend", ['confirm_possible_duplicate' => true], $this->auth)->assertStatus(202);
        $this->assertSame('sent', $message->refresh()->status);
        $this->assertCount(1, $this->fake->submitted);
    }

    public function test_transient_failures_are_retried_later_and_rejections_fail(): void
    {
        $this->fake->failOn('submit', ProviderErrorCode::Unreachable);
        $id = $this->send()->json('id');
        $message = OutboxMessage::query()->findOrFail($id);
        $this->assertSame('queued', $message->status);
        $this->assertSame('UNREACHABLE', $message->last_error_code);
        $this->assertTrue($message->next_attempt_at->isFuture(), 'backoff, not a hot loop');

        $this->fake->failOn('submit', ProviderErrorCode::Rejected);
        $rejected = OutboxMessage::query()->findOrFail($this->send()->json('id'));
        $this->assertSame('failed', $rejected->status);
        $this->assertSame('REJECTED', $rejected->last_error_code);
    }

    /** SEC-T44: only the worker that wins the atomic claim submits. */
    public function test_a_message_already_claimed_by_another_worker_is_not_submitted_again(): void
    {
        $this->fake->failOn('submit', ProviderErrorCode::Unreachable);
        $id = $this->send()->json('id');
        unset($this->fake->failures['submit']);

        OutboxMessage::query()->whereKey($id)->update(['status' => 'sending', 'next_attempt_at' => null]); // in flight elsewhere
        (new SendMessageJob($id))->handle(app(ProviderFactory::class), app(MimeComposer::class), app(AuditLogger::class));
        $this->assertCount(0, $this->fake->submitted);

        OutboxMessage::query()->whereKey($id)->update(['status' => 'queued']);
        $job = new SendMessageJob($id);
        $deps = [app(ProviderFactory::class), app(MimeComposer::class), app(AuditLogger::class)];
        $job->handle(...$deps);
        $job->handle(...$deps);
        $this->assertCount(1, $this->fake->submitted);
    }

    public function test_worker_crash_mid_send_becomes_unknown_not_queued(): void
    {
        $this->fake->failOn('submit', ProviderErrorCode::Unreachable);
        $id = $this->send()->json('id');
        OutboxMessage::query()->whereKey($id)->update(['status' => 'sending', 'claimed_at' => now()->subMinutes(20)]);

        $this->artisan('mbuniemail:prune')->assertSuccessful();

        $this->assertSame('unknown', OutboxMessage::query()->findOrFail($id)->status);
    }

    /** SEC-T22 */
    public function test_hourly_send_limits_contain_a_compromised_session(): void
    {
        config(['mbuniemail.outbox.messages_per_hour' => 2, 'mbuniemail.outbox.recipients_per_hour' => 5]);

        $this->send(['cc' => [], 'bcc' => []])->assertStatus(202);
        $this->send(['cc' => [], 'bcc' => []])->assertStatus(202);
        $this->send(['cc' => [], 'bcc' => []])->assertStatus(429)->assertHeader('Retry-After');
        $this->assertCount(2, $this->fake->submitted);
    }

    public function test_recipient_limit_per_hour(): void
    {
        config(['mbuniemail.outbox.recipients_per_hour' => 4]);

        $this->send()->assertStatus(202); // 3 recipients
        $this->send()->assertStatus(429); // would make 6
    }

    public function test_reply_sets_threading_headers_and_marks_original_answered(): void
    {
        $this->fake->addMessage('INBOX', $this->envelope(1, 'Budget', messageId: 'orig@example.org', references: ['root@example.org']));
        app(SyncService::class)->syncAccount($this->account->refresh(), true);
        $original = MessageMetadata::query()->where('uid', 1)->sole();

        $this->send(['mode' => 'reply', 'reply_to_message_id' => $original->id, 'subject' => 'Re: Budget'])->assertStatus(202);

        [$headers] = explode("\r\n\r\n", $this->submittedRaw(), 2);
        $this->assertStringContainsString('In-Reply-To: <orig@example.org>', $headers);
        $this->assertMatchesRegularExpression('/References: <root@example\.org>\s+<orig@example\.org>/', $headers);
        $this->assertTrue($original->refresh()->is_answered);
    }

    public function test_cannot_reply_to_another_users_message(): void
    {
        $other = $this->linkAccount($this->makeUser(), 'ceo@mt.co.tz');
        $this->fake->addMessage('INBOX', $this->envelope(9));
        app(SyncService::class)->syncAccount($other->refresh(), true);
        $foreign = MessageMetadata::query()->where('email_account_id', $other->id)->firstOrFail();

        $this->send(['mode' => 'reply', 'reply_to_message_id' => $foreign->id])->assertStatus(422);
        $this->assertCount(0, $this->fake->submitted);
    }

    public function test_outgoing_html_is_sanitised_but_keeps_the_authors_images(): void
    {
        $this->send(['html' => '<p>Hi</p><script>alert(1)</script><img src="https://mt.co.tz/logo.png" onerror="x()">'])->assertStatus(202);

        $raw = quoted_printable_decode($this->submittedRaw());
        $this->assertStringNotContainsString('<script', $raw);
        $this->assertStringNotContainsString('onerror', $raw);
        $this->assertStringContainsString('https://mt.co.tz/logo.png', $raw);
        $this->assertStringNotContainsString('image-proxy', $raw, 'internal proxy never leaks into outgoing mail');
        $this->assertStringContainsString('Content-Type: text/plain', $raw, 'plain-text alternative generated');
    }

    /** SEC-T35 / SEC-T36 / SEC-T37 */
    public function test_attachment_uploads_are_validated(): void
    {
        $ok = $this->postJson('/api/v1/outbox/attachments', ['file' => UploadedFile::fake()->createWithContent('../../etc/report.txt', 'quarterly numbers')], $this->auth)
            ->assertCreated()->assertJsonPath('filename', 'report.txt')->assertJsonPath('warning', null);

        $this->freshRequest()->postJson('/api/v1/outbox/attachments', ['file' => UploadedFile::fake()->createWithContent('photo.png', 'this is not a png')], $this->auth)
            ->assertCreated()->assertJsonPath('warning', 'TYPE_MISMATCH');

        $this->freshRequest()->postJson('/api/v1/outbox/attachments', ['file' => UploadedFile::fake()->createWithContent('invoice.pdf.exe', 'MZ')], $this->auth)
            ->assertCreated()->assertJsonPath('warning', 'EXECUTABLE_FILE');

        config(['mbuniemail.outbox.risky_extension_policy' => 'block']);
        $this->freshRequest()->postJson('/api/v1/outbox/attachments', ['file' => UploadedFile::fake()->createWithContent('setup.exe', 'MZ')], $this->auth)
            ->assertStatus(422)->assertJsonPath('code', 'ATTACHMENT_BLOCKED');

        config(['mbuniemail.outbox.max_attachment_bytes' => 1024]);
        $this->freshRequest()->postJson('/api/v1/outbox/attachments', ['file' => UploadedFile::fake()->create('big.pdf', 5)], $this->auth)
            ->assertStatus(422);

        // The stored file uses a random name, never the client's.
        $this->assertNotEmpty(Storage::disk('local')->allFiles('outbox/'.$this->user->id));
        foreach (Storage::disk('local')->allFiles() as $path) {
            $this->assertStringNotContainsString('report', $path);
        }

        $this->send(['attachment_ids' => [$ok->json('id')]])->assertStatus(202);
        $this->assertStringContainsString('report.txt', $this->submittedRaw());
    }

    public function test_attachments_of_other_users_cannot_be_used(): void
    {
        $otherUser = $this->makeUser();
        $otherAuth = $this->bearer($this->freshRequest()->tokensFor($otherUser)['access_token']);
        $foreign = $this->freshRequest()->postJson('/api/v1/outbox/attachments', ['file' => UploadedFile::fake()->createWithContent('secret.txt', 'x')], $otherAuth)->json('id');

        $this->send(['attachment_ids' => [$foreign]])->assertStatus(422);
        $this->freshRequest()->deleteJson("/api/v1/outbox/attachments/{$foreign}", [], $this->auth)->assertStatus(404);
    }

    public function test_delivered_content_is_purged_after_retention(): void
    {
        $id = $this->send()->json('id');
        $this->assertNotNull(OutboxMessage::query()->findOrFail($id)->payload_enc);

        $this->travel(25)->hours();
        $this->artisan('mbuniemail:prune')->assertSuccessful();

        $message = OutboxMessage::query()->findOrFail($id);
        $this->assertNull($message->payload_enc);
        $this->assertSame('sent', $message->status, 'the delivery record itself remains');
    }

    public function test_outbox_status_and_drafts_are_private(): void
    {
        $id = $this->send()->json('id');
        $draftId = (string) Str::ulid();
        $this->freshRequest()->putJson("/api/v1/drafts/{$draftId}", ['account_id' => $this->account->id, 'subject' => 'Unfinished', 'to' => ['x@example.org']], $this->auth)
            ->assertOk()->assertJsonPath('subject', 'Unfinished');
        $this->freshRequest()->getJson('/api/v1/drafts', $this->auth)->assertJsonCount(1, 'data');

        $intruder = $this->bearer($this->freshRequest()->tokensFor($this->makeUser())['access_token']);
        $this->freshRequest()->getJson("/api/v1/outbox/{$id}", $intruder)->assertStatus(404);
        $this->freshRequest()->getJson('/api/v1/drafts', $intruder)->assertJsonCount(0, 'data');
        $this->freshRequest()->deleteJson("/api/v1/drafts/{$draftId}", [], $intruder)->assertStatus(404);

        $this->freshRequest()->deleteJson("/api/v1/drafts/{$draftId}", [], $this->auth)->assertNoContent();
    }
}
