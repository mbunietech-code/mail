<?php

namespace App\Http\Controllers\Api\V1\Mail;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Mailbox\ProviderError;
use App\Mailbox\ProviderErrorResponder;
use App\Mailbox\ProviderFactory;
use App\Models\Folder;
use App\Models\User;
use App\Services\Accounts\AccountService;
use App\Services\Audit\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FolderController extends Controller
{
    public function __construct(
        private readonly ProviderFactory $providers,
        private readonly AccountService $accounts,
        private readonly AuditLogger $audit,
    ) {}

    private const NAME_RULES = ['required', 'string', 'min:1', 'max:100', 'not_regex:/[\x00-\x1F\x7F*%"\\\\]/'];

    /** POST /accounts/{id}/folders — create a custom folder (FR-MAIL-002). */
    public function store(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['name' => self::NAME_RULES]);
        /** @var User $user */
        $user = $request->user();
        $account = $user->usableEmailAccounts()->with('providerProfile', 'credential')->findOrFail($id);

        $provider = $this->providers->forAccount($account);
        try {
            $provider->createFolder($data['name']);
            $this->accounts->syncFolders($account, $provider->listFolders());
        } catch (ProviderError $e) {
            throw ProviderErrorResponder::toApi($e);
        } finally {
            $provider->disconnect();
        }

        $folder = $account->folders()->where('remote_name', $data['name'])->first() ?? $account->folders()->latest()->firstOrFail();

        return response()->json(['id' => $folder->id, 'name' => $folder->remote_name, 'role' => $folder->role], 201);
    }

    /** PATCH /folders/{id} — rename a custom folder, keeping its cached mail. */
    public function update(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['name' => self::NAME_RULES]);
        $folder = $this->customFolder($request, $id);
        $account = $folder->emailAccount;

        // Keep the folder where it is in the hierarchy; only its last segment changes.
        $parent = $folder->delimiter !== null && $folder->delimiter !== '' && str_contains($folder->remote_name, $folder->delimiter)
            ? substr($folder->remote_name, 0, (int) strrpos($folder->remote_name, $folder->delimiter) + strlen($folder->delimiter))
            : '';
        $to = $parent.$data['name'];
        if ($account->folders()->where('remote_name', $to)->exists()) {
            throw new ApiException('FOLDER_EXISTS', 409, 'A folder with this name already exists.');
        }

        $provider = $this->providers->forAccount($account);
        try {
            $provider->renameFolder($folder->remote_name, $to);
            // Re-point the row first so the folder sync keeps it (and its messages).
            $folder->forceFill(['remote_name' => $to])->save();
            $this->accounts->syncFolders($account, $provider->listFolders());
        } catch (ProviderError $e) {
            throw ProviderErrorResponder::toApi($e);
        } finally {
            $provider->disconnect();
        }
        $this->audit->record('folder.renamed', 'success', $request->user(), 'folder', $folder->id);

        return response()->json(['id' => $folder->id, 'name' => $to, 'role' => $folder->role]);
    }

    /** DELETE /folders/{id} — delete a custom folder; its messages go to Trash first. */
    public function destroy(Request $request, string $id): JsonResponse
    {
        $folder = $this->customFolder($request, $id);
        $account = $folder->emailAccount;
        $trash = $account->folders()->where('role', 'trash')->value('remote_name');

        $provider = $this->providers->forAccount($account);
        try {
            $provider->deleteFolder($folder->remote_name, $trash);
            $folder->delete();
            $this->accounts->syncFolders($account, $provider->listFolders());
        } catch (ProviderError $e) {
            throw ProviderErrorResponder::toApi($e);
        } finally {
            $provider->disconnect();
        }
        if ($trash !== null) {
            // Pick the moved messages up in Trash on the next sync.
            $account->forceFill(['next_sync_at' => now()])->save();
        }
        $this->audit->record('folder.deleted', 'success', $request->user(), 'folder', $id);

        return response()->json(null, 204);
    }

    /** Only folders the user made can be renamed or deleted, never Inbox, Sent, Trash and so on. */
    private function customFolder(Request $request, string $id): Folder
    {
        /** @var User $user */
        $user = $request->user();
        $folder = Folder::query()->whereIn('email_account_id', $user->usableEmailAccounts()->select('id'))
            ->with('emailAccount.providerProfile', 'emailAccount.credential')->findOrFail($id);

        if ($folder->role !== 'custom' || strcasecmp($folder->remote_name, 'INBOX') === 0) {
            throw new ApiException('FOLDER_PROTECTED', 422, 'System folders cannot be renamed or deleted.');
        }

        return $folder;
    }
}
