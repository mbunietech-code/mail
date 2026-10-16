<?php

namespace Tests\Feature\Mail;

use App\Mailbox\Data\AttachmentInfo;
use App\Mailbox\ProviderErrorCode;
use App\Models\EmailAccount;
use App\Models\Folder;
use App\Models\MessageMetadata;
use App\Models\User;
use App\Services\Mail\SyncService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\MailFixtures;
use Tests\TestCase;

class MessageApiTest extends TestCase
{
    use MailFixtures;

    private User $user;

    private EmailAccount $account;

    /** @var array<string, string> */
    private array $auth;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake(); // follow-up syncs dispatched by moves are not under test here
        $this->useFakeProvider();
        $this->user = $this->makeUser();
        $this->account = $this->linkAccount($this->user);
        $this->auth = $this->bearer($this->tokensFor($this->user)['access_token']);
    }

    private function seedInbox(int $count = 3): void
    {
        for ($i = 1; $i <= $count; $i++) {
            $this->fake->addMessage('INBOX', $this->envelope($i, "Message {$i}", date: CarbonImmutable::parse('2026-10-01 10:00')->addHours($i)), "Body {$i}", "<p>Body {$i}</p>");
        }
        app(SyncService::class)->syncAccount($this->account->refresh(), true);
    }

    private function folder(string $role = 'inbox'): Folder
    {
        return $this->account->folders()->where('role', $role)->firstOrFail();
    }

    private function message(int $uid): MessageMetadata
    {
        return MessageMetadata::query()->where('uid', $uid)->firstOrFail();
    }

    public function test_list_is_newest_first_with_stable_cursor_pagination(): void
    {
        $this->seedInbox(5);

        $page1 = $this->getJson("/api/v1/folders/{$this->folder()->id}/messages?limit=2", $this->auth)->assertOk();
        $this->assertSame(['Message 5', 'Message 4'], $page1->json('data.*.subject'));
        $this->assertNotNull($cursor = $page1->json('next_cursor'));

        $page2 = $this->freshRequest()->getJson("/api/v1/folders/{$this->folder()->id}/messages?limit=2&cursor={$cursor}", $this->auth)->assertOk();
        $this->assertSame(['Message 3', 'Message 2'], $page2->json('data.*.subject'));

        $page3 = $this->freshRequest()->getJson("/api/v1/folders/{$this->folder()->id}/messages?limit=2&cursor=".$page2->json('next_cursor'), $this->auth);
        $this->assertSame(['Message 1'], $page3->json('data.*.subject'));
        $this->assertNull($page3->json('next_cursor'));

        $this->freshRequest()->getJson("/api/v1/folders/{$this->folder()->id}/messages?limit=500", $this->auth)->assertStatus(422);
        $this->freshRequest()->getJson("/api/v1/folders/{$this->folder()->id}/messages?cursor=garbage", $this->auth)->assertStatus(422);
    }

    public function test_list_items_carry_snippet_flags_and_external_marker(): void
    {
        $this->seedInbox(1);

        $item = $this->getJson("/api/v1/folders/{$this->folder()->id}/messages", $this->auth)->json('data.0');
        $this->assertSame('Preview text', $item['snippet']);
        $this->assertFalse($item['is_seen']);
        $this->assertTrue($item['is_external']);
        $this->assertSame('partner@example.org', $item['from']['email']);
    }

    /** SEC-T16 end-to-end: what the API returns has no active content. */
    public function test_reading_returns_sanitised_html_and_blocks_remote_images_by_default(): void
    {
        $this->fake->addMessage('INBOX', $this->envelope(1), 'plain', '<p onclick="steal()">Hi</p><script>alert(1)</script><img src="https://tracker.example/p.gif">');
        app(SyncService::class)->syncAccount($this->account->refresh(), true);

        $body = $this->getJson("/api/v1/messages/{$this->message(1)->id}", $this->auth)->assertOk()->json('body');

        $this->assertStringNotContainsString('script', $body['html']);
        $this->assertStringNotContainsString('onclick', $body['html']);
        $this->assertStringNotContainsString('tracker.example', $body['html']);
        $this->assertSame(1, $body['remote_images_blocked']);
        $this->assertFalse($body['remote_images_allowed']);

        $allowed = $this->freshRequest()->getJson("/api/v1/messages/{$this->message(1)->id}?images=allow", $this->auth)->json('body.html');
        $this->assertStringContainsString('/api/v1/image-proxy?', html_entity_decode($allowed));
        $this->assertStringNotContainsString('src="https://tracker.example', html_entity_decode($allowed), 'never loaded directly');
    }

    public function test_reader_flags_phishing_indicators(): void
    {
        $this->fake->addMessage('INBOX', $this->envelope(1, from: 'ceo@mt-co.tz', fromName: 'CEO ceo@mt.co.tz', authResults: 'mx.hostinger.com; spf=fail smtp.mailfrom=mt-co.tz; dkim=none; dmarc=fail'));
        app(SyncService::class)->syncAccount($this->account->refresh(), true);

        $security = $this->getJson("/api/v1/messages/{$this->message(1)->id}", $this->auth)->json('security');

        $this->assertTrue($security['external_sender']);
        $this->assertTrue($security['lookalike_domain']);
        $this->assertTrue($security['display_name_spoof']);
        $this->assertSame('fail', $security['spf']);
        $this->assertSame('fail', $security['dmarc']);
    }

    public function test_internal_sender_is_not_external(): void
    {
        $this->fake->addMessage('INBOX', $this->envelope(1, from: 'ceo@mt.co.tz'));
        app(SyncService::class)->syncAccount($this->account->refresh(), true);

        $this->assertFalse($this->getJson("/api/v1/messages/{$this->message(1)->id}", $this->auth)->json('security.external_sender'));
    }

    public function test_flag_changes_are_written_to_the_provider_then_cache(): void
    {
        $this->seedInbox(1);
        $id = $this->message(1)->id;

        $this->patchJson("/api/v1/messages/{$id}", ['is_seen' => true, 'is_flagged' => true], $this->auth)->assertOk()
            ->assertJsonPath('is_seen', true)->assertJsonPath('is_flagged', true);

        $this->assertSame([['uid' => 1, 'seen' => true, 'flagged' => true]], $this->fake->flagCalls);
        $this->assertSame(0, $this->folder()->refresh()->unread_count);
    }

    public function test_provider_failure_leaves_cache_unchanged(): void
    {
        $this->seedInbox(1);
        $this->fake->failOn('setFlags', ProviderErrorCode::Unreachable);

        $this->patchJson("/api/v1/messages/{$this->message(1)->id}", ['is_seen' => true], $this->auth)->assertStatus(502)->assertJsonPath('code', 'PROVIDER_UNREACHABLE');
        $this->assertFalse($this->message(1)->is_seen);
    }

    public function test_delete_moves_to_trash_and_permanent_delete_only_from_trash(): void
    {
        $this->seedInbox(1);
        $id = $this->message(1)->id;

        $this->deleteJson("/api/v1/messages/{$id}?permanent=1", [], $this->auth)->assertStatus(409);
        $this->freshRequest()->deleteJson("/api/v1/messages/{$id}", [], $this->auth)->assertNoContent();

        $this->assertArrayHasKey(1, $this->fake->messages['Trash']);
        $this->assertArrayNotHasKey(1, $this->fake->messages['INBOX']);
        $this->assertSame(0, MessageMetadata::query()->count(), 'cache row removed; next sync caches the Trash copy');
    }

    public function test_deleting_from_trash_deletes_forever_one_or_many(): void
    {
        $this->seedInbox(3);
        foreach ([1, 2, 3] as $uid) {
            $this->freshRequest()->deleteJson("/api/v1/messages/{$this->message($uid)->id}", [], $this->auth)->assertNoContent();
        }
        app(SyncService::class)->syncAccount($this->account->refresh(), true);
        $inTrash = MessageMetadata::query()->where('folder_id', $this->folder('trash')->id)->orderBy('uid')->pluck('id')->all();
        $this->assertCount(3, $inTrash);

        $this->freshRequest()->deleteJson("/api/v1/messages/{$inTrash[0]}", [], $this->auth)->assertNoContent();
        $this->freshRequest()->postJson('/api/v1/messages/bulk', ['ids' => [$inTrash[1], $inTrash[2]], 'action' => 'trash'], $this->auth)->assertOk()
            ->assertJsonPath("results.{$inTrash[1]}", 'ok')
            ->assertJsonPath("results.{$inTrash[2]}", 'ok');

        $this->assertSame([], $this->fake->messages['Trash']);
        $this->assertSame(0, MessageMetadata::query()->count());
    }

    public function test_spam_and_bulk_actions(): void
    {
        $this->seedInbox(3);

        $this->postJson("/api/v1/messages/{$this->message(1)->id}/spam", ['spam' => true], $this->auth)->assertNoContent();
        $this->assertArrayHasKey(1, $this->fake->messages['Junk']);

        $ids = [$this->message(2)->id, $this->message(3)->id, '01JZZZZZZZZZZZZZZZZZZZZZZZ'];
        $this->freshRequest()->postJson('/api/v1/messages/bulk', ['ids' => $ids, 'action' => 'seen'], $this->auth)->assertOk()
            ->assertJsonPath("results.{$ids[0]}", 'ok')
            ->assertJsonPath("results.{$ids[2]}", 'not_found');
        $this->assertCount(2, $this->fake->flagCalls);
    }

    public function test_thread_view_returns_conversation_oldest_first(): void
    {
        $this->fake->addMessage('INBOX', $this->envelope(1, 'Plan', messageId: 'a@x', date: CarbonImmutable::parse('2026-10-01')));
        $this->fake->addMessage('INBOX', $this->envelope(2, 'Re: Plan', messageId: 'b@x', inReplyTo: 'a@x', date: CarbonImmutable::parse('2026-10-02')));
        app(SyncService::class)->syncAccount($this->account->refresh(), true);

        $key = $this->message(1)->thread_key;
        $this->getJson("/api/v1/threads/{$key}?account_id={$this->account->id}", $this->auth)->assertOk()
            ->assertJsonPath('data.0.subject', 'Plan')->assertJsonPath('data.1.subject', 'Re: Plan');
    }

    public function test_attachments_download_as_opaque_files(): void
    {
        $this->fake->addMessage('INBOX', $this->envelope(1, attachments: [new AttachmentInfo('2', 'invoice.html', 'text/html', 20)]));
        app(SyncService::class)->syncAccount($this->account->refresh(), true);
        $attachmentId = $this->getJson("/api/v1/messages/{$this->message(1)->id}", $this->auth)->json('attachments.0.id');

        $response = $this->freshRequest()->get("/api/v1/attachments/{$attachmentId}?inline=1", $this->auth)->assertOk();

        $this->assertSame('application/octet-stream', $response->headers->get('Content-Type'), 'HTML is never served renderable');
        $this->assertStringStartsWith('attachment;', (string) $response->headers->get('Content-Disposition'));
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        $this->assertStringContainsString('sandbox', (string) $response->headers->get('Content-Security-Policy'));
    }

    public function test_search_combines_cached_filters_with_provider_text_search(): void
    {
        $this->seedInbox(3);

        $this->getJson("/api/v1/accounts/{$this->account->id}/search?subject=Message 2", $this->auth)->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.subject', 'Message 2');
        $this->freshRequest()->getJson("/api/v1/accounts/{$this->account->id}/search?from=partner", $this->auth)->assertJsonCount(3, 'data');
        $this->freshRequest()->getJson("/api/v1/accounts/{$this->account->id}/search", $this->auth)->assertStatus(422);
        $this->freshRequest()->getJson("/api/v1/accounts/{$this->account->id}/search?q=".rawurlencode("x\r\nA DELETE"), $this->auth)->assertStatus(422);
    }

    /** SEC-T13: every content route hides other users' data. */
    public function test_other_users_cannot_reach_any_message_content(): void
    {
        $this->fake->addMessage('INBOX', $this->envelope(1, attachments: [new AttachmentInfo('2', 'a.pdf', 'application/pdf', 20)]));
        app(SyncService::class)->syncAccount($this->account->refresh(), true);
        $message = $this->message(1);
        $attachmentId = $this->getJson("/api/v1/messages/{$message->id}", $this->auth)->json('attachments.0.id');
        $folder = $this->folder();

        $intruder = $this->bearer($this->freshRequest()->tokensFor($this->makeUser())['access_token']);
        $requests = [
            ['GET', "/api/v1/folders/{$folder->id}/messages"],
            ['GET', "/api/v1/messages/{$message->id}"],
            ['PATCH', "/api/v1/messages/{$message->id}", ['is_seen' => true]],
            ['DELETE', "/api/v1/messages/{$message->id}"],
            ['POST', "/api/v1/messages/{$message->id}/move", ['folder_id' => $this->folder('trash')->id]],
            ['POST', "/api/v1/messages/{$message->id}/spam", ['spam' => true]],
            ['GET', "/api/v1/threads/{$message->thread_key}?account_id={$this->account->id}"],
            ['GET', "/api/v1/attachments/{$attachmentId}"],
            ['GET', "/api/v1/accounts/{$this->account->id}/search?from=x"],
            ['POST', "/api/v1/accounts/{$this->account->id}/sync"],
            ['POST', "/api/v1/accounts/{$this->account->id}/folders", ['name' => 'X']],
        ];
        foreach ($requests as $r) {
            $this->freshRequest()->json($r[0], $r[1], $r[2] ?? [], $intruder)->assertStatus(404);
        }

        $this->freshRequest()->postJson('/api/v1/messages/bulk', ['ids' => [$message->id], 'action' => 'trash'], $intruder)
            ->assertJsonPath("results.{$message->id}", 'not_found');
        $this->assertSame([], $this->fake->flagCalls);
        $this->assertArrayHasKey(1, $this->fake->messages['INBOX']);
    }

    public function test_moving_into_another_users_folder_is_impossible(): void
    {
        $this->seedInbox(1);
        $other = $this->linkAccount($this->makeUser(), 'ceo@mt.co.tz');
        $foreignFolder = $other->folders()->where('role', 'trash')->firstOrFail();

        $this->postJson("/api/v1/messages/{$this->message(1)->id}/move", ['folder_id' => $foreignFolder->id], $this->auth)->assertStatus(404);
    }

    public function test_manual_refresh_is_rate_limited(): void
    {
        for ($i = 0; $i < 6; $i++) {
            $this->freshRequest()->postJson("/api/v1/accounts/{$this->account->id}/sync", [], $this->auth)->assertStatus(202);
        }
        $this->freshRequest()->postJson("/api/v1/accounts/{$this->account->id}/sync", [], $this->auth)->assertStatus(429);
    }

    public function test_sync_status_reports_accounts_and_unread_counts(): void
    {
        $this->seedInbox(2);

        $accounts = collect($this->getJson('/api/v1/sync/status', $this->auth)->assertOk()->json('accounts'));
        $mine = $accounts->firstWhere('id', $this->account->id);

        $this->assertSame('active', $mine['status']);
        $this->assertSame(2, $mine['unread_inbox']);
    }
}
