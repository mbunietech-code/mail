<?php

namespace Tests\Feature\Mail;

use App\Jobs\PushNewMailJob;
use App\Models\Device;
use App\Models\EmailAccount;
use App\Models\MessageMetadata;
use App\Services\Mail\SyncService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\MailFixtures;
use Tests\TestCase;

/** FR-NOTIF-001/002: Android push for new mail. */
class PushNotificationTest extends TestCase
{
    use MailFixtures;

    private EmailAccount $account;

    protected function setUp(): void
    {
        parent::setUp();
        $this->useFakeProvider();
        $this->account = $this->linkAccount($this->makeUser());
    }

    public function test_device_registers_its_push_token_and_logout_clears_it(): void
    {
        $user = $this->makeUser();
        $tokens = $this->tokensFor($user);
        $auth = $this->bearer($tokens['access_token']);

        $this->putJson('/api/v1/me/push-token', ['token' => 'fcm-token-1'], $auth)->assertNoContent();
        $device = Device::query()->where('user_id', $user->id)->sole();
        $this->assertSame('fcm-token-1', $device->push_token);
        $this->assertStringNotContainsString('fcm-token-1', (string) $device->getRawOriginal('push_token'), 'token encrypted at rest');

        $this->freshRequest()->putJson('/api/v1/me/push-token', [], $auth)->assertStatus(422);

        $this->freshRequest()->postJson('/api/v1/auth/logout', [], $auth)->assertNoContent();
        $this->assertNull($device->refresh()->push_token);

        $this->freshRequest()->putJson('/api/v1/me/push-token', ['token' => 'x'])->assertUnauthorized();
    }

    public function test_sync_pushes_only_new_unseen_inbox_mail_never_the_backlog(): void
    {
        Queue::fake([PushNewMailJob::class]);

        $this->fake->addMessage('INBOX', $this->envelope(1, 'Old mail'));
        $this->sync();
        Queue::assertNotPushed(PushNewMailJob::class);

        $this->fake->addMessage('INBOX', $this->envelope(2, 'Fresh'));
        $this->fake->addMessage('INBOX', $this->envelope(3, 'Already read', seen: true));
        $this->sync();

        $fresh = MessageMetadata::query()->where('uid', 2)->value('id');
        Queue::assertPushed(PushNewMailJob::class, fn (PushNewMailJob $job) => $job->accountId === $this->account->id && $job->messageIds === [$fresh]);
        Queue::assertPushedTimes(PushNewMailJob::class, 1);
    }

    public function test_job_sends_private_payload_and_drops_dead_tokens(): void
    {
        config(['mbuniemail.push.fcm_credentials' => $this->serviceAccountFile()]);
        $this->fake->addMessage('INBOX', $this->envelope(1, 'Salary revisions 2027'));
        $this->sync();
        $message = MessageMetadata::query()->sole();
        $live = $this->device('live-token');
        $dead = $this->device('dead-token');

        Http::fake([
            'oauth2.googleapis.com/*' => Http::response(['access_token' => 'ya29.test', 'expires_in' => 3600]),
            'fcm.googleapis.com/*' => fn (Request $r) => $r['message']['token'] === 'dead-token'
                ? Http::response(['error' => ['status' => 'UNREGISTERED']], 404)
                : Http::response(['name' => 'projects/p/messages/1']),
        ]);

        PushNewMailJob::dispatchSync($this->account->id, [$message->id]);

        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'fcm.googleapis.com/v1/projects/test-project/messages:send')
            && $r['message']['token'] === 'live-token'
            && $r->hasHeader('Authorization', 'Bearer ya29.test')
            && $r['message']['data'] === ['account_id' => $this->account->id, 'message_id' => $message->id]
            && $r['message']['android']['notification']['visibility'] === 'PRIVATE'
            && ! str_contains(json_encode($r->data()), 'Salary'));
        $this->assertSame('live-token', $live->refresh()->push_token);
        $this->assertNull($dead->refresh()->push_token);
    }

    public function test_job_does_nothing_when_push_is_not_configured_or_mail_was_read(): void
    {
        $this->fake->addMessage('INBOX', $this->envelope(1));
        $this->sync();
        $message = MessageMetadata::query()->sole();
        $this->device('live-token');
        Http::fake();

        config(['mbuniemail.push.fcm_credentials' => '']);
        PushNewMailJob::dispatchSync($this->account->id, [$message->id]);

        config(['mbuniemail.push.fcm_credentials' => $this->serviceAccountFile()]);
        $message->forceFill(['is_seen' => true])->save();
        PushNewMailJob::dispatchSync($this->account->id, [$message->id]);

        Http::assertNothingSent();
    }

    private function sync(): void
    {
        app(SyncService::class)->syncAccount($this->account->refresh(), true);
    }

    private function device(string $token): Device
    {
        $device = Device::query()->create(['user_id' => $this->account->user_id, 'client_device_id' => uniqid('d', true), 'platform' => 'android']);
        $device->forceFill(['push_token' => $token])->save();

        return $device;
    }

    private function serviceAccountFile(): string
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($key, $pem);
        $path = tempnam(sys_get_temp_dir(), 'fcm');
        file_put_contents($path, json_encode([
            'type' => 'service_account',
            'project_id' => 'test-project',
            'client_email' => 'push@test-project.iam.gserviceaccount.com',
            'private_key' => $pem,
            'token_uri' => 'https://oauth2.googleapis.com/token',
        ]));
        $this->beforeApplicationDestroyed(fn () => @unlink($path));

        return $path;
    }
}
