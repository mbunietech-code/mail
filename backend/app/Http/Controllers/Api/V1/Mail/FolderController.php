<?php

namespace App\Http\Controllers\Api\V1\Mail;

use App\Http\Controllers\Controller;
use App\Mailbox\ProviderError;
use App\Mailbox\ProviderErrorResponder;
use App\Mailbox\ProviderFactory;
use App\Models\User;
use App\Services\Accounts\AccountService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FolderController extends Controller
{
    public function __construct(
        private readonly ProviderFactory $providers,
        private readonly AccountService $accounts,
    ) {}

    /** POST /accounts/{id}/folders — create a custom folder (FR-MAIL-002). */
    public function store(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'min:1', 'max:100', 'not_regex:/[\x00-\x1F\x7F*%"\\\\]/']]);
        /** @var User $user */
        $user = $request->user();
        $account = $user->emailAccounts()->with('providerProfile', 'credential')->findOrFail($id);

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
}
