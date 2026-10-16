<?php

namespace Tests\Feature\Mail;

use App\Models\MessageMetadata;
use App\Services\Notifications\NewMailPayload;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class ContactsPreferencesNotificationsTest extends TestCase
{
    public function test_autocomplete_returns_own_contacts_and_colleagues_only(): void
    {
        $me = $this->makeUser(['name' => 'Asha Developer']);
        $colleague = $this->makeUser(['name' => 'Baraka Finance', 'email' => 'baraka@mt.co.tz']);
        $this->makeUser(['name' => 'Bakari Gone', 'email' => 'bakari@mt.co.tz', 'status' => 'deactivated']);
        $other = $this->makeUser();
        foreach ([[$me->id, 'banker@bank.co.tz', 'Bank Contact'], [$other->id, 'bob-secret@private.example', 'Bob Private']] as [$uid, $email, $name]) {
            DB::table('contacts')->insert(['id' => (string) Str::ulid(), 'user_id' => $uid, 'email' => $email, 'name' => $name, 'source' => 'derived', 'use_count' => 1, 'created_at' => now(), 'updated_at' => now()]);
        }
        $auth = $this->bearer($this->tokensFor($me)['access_token']);

        $emails = $this->getJson('/api/v1/contacts/suggest?q=ba', $auth)->assertOk()->json('data.*.email');
        $this->assertContains('banker@bank.co.tz', $emails);
        $this->assertContains($colleague->email, $emails);
        $this->assertNotContains('bakari@mt.co.tz', $emails, 'deactivated employees hidden');

        $this->assertSame([], $this->freshRequest()->getJson('/api/v1/contacts/suggest?q=bob', $auth)->json('data'), "another user's contacts never leak");
        $this->freshRequest()->getJson('/api/v1/contacts/suggest?q=%25%25', $auth)->assertOk()->assertJsonCount(0, 'data');
        $this->freshRequest()->getJson('/api/v1/contacts/suggest?q=a', $auth)->assertStatus(422);
    }

    public function test_preferences_have_private_defaults_and_validate_changes(): void
    {
        $auth = $this->bearer($this->tokensFor($this->makeUser())['access_token']);

        $this->getJson('/api/v1/me/preferences', $auth)->assertOk()
            ->assertJsonPath('remote_images', 'ask')
            ->assertJsonPath('show_subject_in_notifications', false);

        $this->freshRequest()->patchJson('/api/v1/me/preferences', ['theme' => 'dark', 'remote_images' => 'trusted_senders', 'trusted_image_senders' => ['news@mt.co.tz']], $auth)
            ->assertOk()->assertJsonPath('theme', 'dark')->assertJsonPath('trusted_image_senders.0', 'news@mt.co.tz');
        $this->freshRequest()->patchJson('/api/v1/me/preferences', ['theme' => 'neon'], $auth)->assertStatus(422);
        $this->freshRequest()->patchJson('/api/v1/me/preferences', ['default_account_id' => (string) Str::ulid()], $auth)->assertStatus(422);
    }

    /** SEC-T38 */
    public function test_notifications_reveal_no_subject_or_body_by_default(): void
    {
        $message = new MessageMetadata;
        $message->forceFill(['id' => (string) Str::ulid(), 'email_account_id' => (string) Str::ulid(), 'from_name' => 'Finance Team', 'from_email' => 'finance@mt.co.tz', 'subject_enc' => 'Salary revisions 2027', 'snippet_enc' => 'Confidential figures']);

        $private = NewMailPayload::build($message, showSubject: false);
        $encoded = json_encode($private);
        $this->assertStringNotContainsString('Salary', $encoded);
        $this->assertStringNotContainsString('Confidential', $encoded);
        $this->assertSame('Finance Team', $private['title']);
        $this->assertSame('Sent you a new message', $private['body']);
        $this->assertSame('private', $private['visibility']);
        $this->assertSame(['account_id', 'message_id', 'sender', 'count', 'received_at'], array_keys($private['data']));

        $optedIn = NewMailPayload::build($message, showSubject: true);
        $this->assertSame('Salary revisions 2027', $optedIn['body']);
        $this->assertSame('Salary revisions 2027', $optedIn['data']['subject']);
        $this->assertStringNotContainsString('Confidential', json_encode($optedIn), 'snippets are never sent');
    }
}
