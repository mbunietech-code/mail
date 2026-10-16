<?php

namespace Tests\Feature\Mail;

use App\Mailbox\ProviderErrorCode;
use App\Models\AuditLog;
use App\Models\EmailAccount;
use App\Models\Folder;
use App\Models\MessageMetadata;
use App\Models\User;
use App\Services\Mail\SyncService;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\MailFixtures;
use Tests\TestCase;

/** FR-MAIL-002: custom folders can be renamed and deleted; system folders cannot. */
class FolderManagementTest extends TestCase
{
    use MailFixtures;

    private User $user;

    private EmailAccount $account;

    /** @var array<string, string> */
    private array $auth;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->useFakeProvider();
        $this->user = $this->makeUser();
        $this->account = $this->linkAccount($this->user);
        $this->auth = $this->bearer($this->tokensFor($this->user)['access_token']);
    }

    private function createFolder(string $name): Folder
    {
        $id = $this->freshRequest()->postJson("/api/v1/accounts/{$this->account->id}/folders", ['name' => $name], $this->auth)
            ->assertCreated()->json('id');

        return Folder::query()->findOrFail($id);
    }

    public function test_renaming_keeps_the_folder_and_its_cached_messages(): void
    {
        $folder = $this->createFolder('Projects');
        $this->fake->addMessage('Projects', $this->envelope(7, 'Plan'));
        app(SyncService::class)->syncAccount($this->account->refresh(), true);
        $this->assertSame(1, MessageMetadata::query()->where('folder_id', $folder->id)->count());

        $this->freshRequest()->patchJson("/api/v1/folders/{$folder->id}", ['name' => 'Clients'], $this->auth)
            ->assertOk()->assertJsonPath('id', $folder->id)->assertJsonPath('name', 'Clients');

        $this->assertSame('Clients', $folder->refresh()->remote_name);
        $this->assertArrayHasKey('Clients', $this->fake->folders);
        $this->assertArrayNotHasKey('Projects', $this->fake->folders);
        $this->assertSame(1, MessageMetadata::query()->where('folder_id', $folder->id)->count());
        $this->assertTrue(AuditLog::query()->where('action', 'folder.renamed')->exists());
    }

    public function test_renaming_to_an_existing_name_is_refused(): void
    {
        $folder = $this->createFolder('Projects');
        $this->createFolder('Clients');

        $this->freshRequest()->patchJson("/api/v1/folders/{$folder->id}", ['name' => 'Clients'], $this->auth)
            ->assertStatus(409)->assertJsonPath('code', 'FOLDER_EXISTS');
    }

    public function test_deleting_moves_messages_to_trash_then_removes_the_folder(): void
    {
        $folder = $this->createFolder('Old');
        $this->fake->addMessage('Old', $this->envelope(9, 'Keep me'));

        $this->freshRequest()->deleteJson("/api/v1/folders/{$folder->id}", [], $this->auth)->assertNoContent();

        $this->assertNull(Folder::query()->find($folder->id));
        $this->assertArrayNotHasKey('Old', $this->fake->folders);
        $this->assertArrayHasKey(9, $this->fake->messages['Trash']);
        $this->assertTrue(AuditLog::query()->where('action', 'folder.deleted')->exists());
    }

    public function test_system_folders_are_protected(): void
    {
        foreach (['inbox', 'sent', 'trash'] as $role) {
            $id = $this->account->folders()->where('role', $role)->value('id');
            $this->freshRequest()->patchJson("/api/v1/folders/{$id}", ['name' => 'X'], $this->auth)
                ->assertStatus(422)->assertJsonPath('code', 'FOLDER_PROTECTED');
            $this->freshRequest()->deleteJson("/api/v1/folders/{$id}", [], $this->auth)
                ->assertStatus(422)->assertJsonPath('code', 'FOLDER_PROTECTED');
        }
        $this->assertArrayHasKey('INBOX', $this->fake->folders);
    }

    public function test_other_users_folders_are_invisible(): void
    {
        $folder = $this->createFolder('Private');
        $intruder = $this->bearer($this->freshRequest()->tokensFor($this->makeUser())['access_token']);

        $this->freshRequest()->patchJson("/api/v1/folders/{$folder->id}", ['name' => 'Mine'], $intruder)->assertStatus(404);
        $this->freshRequest()->deleteJson("/api/v1/folders/{$folder->id}", [], $intruder)->assertStatus(404);
        $this->assertArrayHasKey('Private', $this->fake->folders);
    }

    public function test_provider_failure_leaves_the_folder_untouched(): void
    {
        $folder = $this->createFolder('Projects');
        $this->fake->failOn('renameFolder', ProviderErrorCode::Unreachable);

        $this->freshRequest()->patchJson("/api/v1/folders/{$folder->id}", ['name' => 'Clients'], $this->auth)->assertStatus(502);

        $this->assertSame('Projects', $folder->refresh()->remote_name);
    }
}
