<?php

namespace App\Http\Controllers\Api\V1\Accounts;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Mailbox\ProviderErrorCode;
use App\Models\EmailAccount;
use App\Models\Folder;
use App\Models\ProviderProfile;
use App\Models\User;
use App\Security\Html\EmailHtmlSanitizer;
use App\Security\Secret;
use App\Services\Accounts\AccountService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AccountController extends Controller
{
    public function __construct(
        private readonly AccountService $accounts,
        private readonly EmailHtmlSanitizer $sanitizer,
    ) {}

    public function index(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return response()->json([
            'data' => $user->emailAccounts()->with('providerProfile')->orderBy('created_at')->get()->map(self::present(...)),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email_address' => ['required', 'string', 'email', 'max:254'],
            'password' => ['required', 'string', 'max:256'],
            'display_name' => ['nullable', 'string', 'max:120'],
        ]);

        /** @var User $user */
        $user = $request->user();
        $account = $this->accounts->linkImapAccount($user, $data['email_address'], new Secret($data['password']), $data['display_name'] ?? null);

        return response()->json(self::present($account->load('providerProfile')), 201);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        return response()->json(self::present($this->owned($request, $id)));
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $account = $this->owned($request, $id);
        $data = $request->validate([
            'display_name' => ['sometimes', 'nullable', 'string', 'max:120'],
            'signature_html' => ['sometimes', 'nullable', 'string', 'max:20000'],
        ]);
        if (array_key_exists('display_name', $data)) {
            $account->display_name = $data['display_name'];
        }
        if (array_key_exists('signature_html', $data)) {
            // Signatures are inserted into outgoing mail: same allow-list as composing (FR-SEND-008).
            $account->signature_html = $data['signature_html'] === null ? null : $this->sanitizer->sanitizeOutbound($data['signature_html']);
        }
        $account->save();

        return response()->json(self::present($account));
    }

    public function updateCredential(Request $request, string $id): JsonResponse
    {
        $account = $this->owned($request, $id);
        $data = $request->validate(['password' => ['required', 'string', 'max:256']]);

        if ($account->providerProfile->driver !== ProviderProfile::DRIVER_IMAP_SMTP) {
            throw new ApiException('PROVIDER_NOT_SUPPORTED', 422, 'This account uses Google sign-in; reconnect it instead.');
        }

        return response()->json(self::present($this->accounts->updatePassword($account, new Secret($data['password']))));
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $this->accounts->unlink($this->owned($request, $id), $user);

        return response()->json(null, 204);
    }

    public function diagnostics(Request $request, string $id): JsonResponse
    {
        $account = $this->owned($request, $id);
        $code = $account->last_error_code !== null ? ProviderErrorCode::tryFrom($account->last_error_code) : null;

        return response()->json([
            ...self::present($account),
            'remedy' => $code?->remedy(),
            'folders' => $account->folders()->orderBy('remote_name')->get(['id', 'remote_name', 'role', 'selectable'])
                ->map(fn (Folder $f) => ['id' => $f->id, 'name' => $f->remote_name, 'role' => $f->role, 'selectable' => $f->selectable]),
        ]);
    }

    public function folders(Request $request, string $id): JsonResponse
    {
        $account = $this->owned($request, $id);
        $order = array_flip(Folder::ROLES);

        $folders = $account->folders()->get()
            ->sortBy(fn (Folder $f) => [$order[$f->role] ?? 99, strtolower($f->remote_name)])
            ->values()
            ->map(fn (Folder $f) => [
                'id' => $f->id,
                'name' => $f->remote_name,
                'role' => $f->role,
                'unread_count' => $f->unread_count,
                'total_count' => $f->total_count,
                'selectable' => $f->selectable,
            ]);

        return response()->json(['data' => $folders]);
    }

    /** Object-level authorisation: only the caller's own accounts resolve; others are 404. */
    private function owned(Request $request, string $id): EmailAccount
    {
        /** @var User $user */
        $user = $request->user();

        return $user->emailAccounts()->with('providerProfile')->findOrFail($id);
    }

    /** @return array<string, mixed> */
    public static function present(EmailAccount $account): array
    {
        return [
            'id' => $account->id,
            'email_address' => $account->email_address,
            'display_name' => $account->display_name,
            'provider' => $account->providerProfile->key,
            // A mailbox on a domain the administrator removed stays listed (so it can be
            // unlinked) but cannot be read, synced or sent from.
            'status' => $account->hasAllowedDomain() ? $account->status : 'domain_not_allowed',
            'last_synced_at' => $account->last_synced_at?->toIso8601String(),
            'last_error_code' => $account->last_error_code,
            'capabilities' => $account->capabilities ?? (object) [],
            'signature_html' => $account->signature_html,
        ];
    }
}
