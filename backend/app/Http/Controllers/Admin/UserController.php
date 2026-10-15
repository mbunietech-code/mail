<?php

namespace App\Http\Controllers\Admin;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\EmailAccount;
use App\Models\Role;
use App\Models\User;
use App\Services\Admin\UserAdminService;
use App\Services\Auth\InvitationService;
use App\Support\CompanyDomains;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Employee administration. Views show account *metadata* only (status, last sync,
 * error codes) — never message lists, subjects or bodies (FR-ADM-007, US-07).
 */
class UserController extends Controller
{
    public function __construct(
        private readonly UserAdminService $admin,
        private readonly InvitationService $invitations,
    ) {}

    public function index(Request $request): View
    {
        $data = $request->validate(['q' => ['nullable', 'string', 'max:100'], 'status' => ['nullable', 'in:active,suspended,deactivated,invited'], 'domain' => ['nullable', 'string', 'max:253']]);
        $like = '%'.addcslashes(strtolower((string) ($data['q'] ?? '')), '\\%_').'%';

        $users = User::query()->with('roles')
            ->when($data['q'] ?? null, fn ($q) => $q->where(fn ($q) => $q->whereRaw('LOWER(email) LIKE ?', [$like])->orWhereRaw('LOWER(name) LIKE ?', [$like])))
            ->when($data['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->when($data['domain'] ?? null, fn ($q, $d) => $q->where('email_domain', strtolower($d)))
            ->orderBy('name')->paginate(50)->withQueryString();

        return view('admin.users.index', ['users' => $users, 'roles' => Role::query()->orderBy('name')->get(), 'filters' => $data, 'domains' => CompanyDomains::all()]);
    }

    public function show(User $user): View
    {
        $user->load('roles');

        return view('admin.users.show', [
            'subject' => $user,
            'roles' => Role::query()->orderBy('name')->get(),
            'accounts' => $user->emailAccounts()->with('providerProfile')->get(['id', 'user_id', 'provider_profile_id', 'email_address', 'status', 'last_synced_at', 'last_error_code']),
            'sessions' => $user->refreshSessions()->usable()->with('device')->latest()->get(),
        ]);
    }

    public function invite(Request $request): RedirectResponse
    {
        $data = $request->validate(['email' => ['required', 'email', 'max:254'], 'role' => ['required', 'exists:roles,name']]);
        /** @var User $actor */
        $actor = $request->user();
        $role = Role::query()->where('name', $data['role'])->firstOrFail();

        if ($role->is_admin && ! $actor->hasPermission('admins.manage')) {
            return back()->withErrors(['role' => 'Only a super administrator can invite administrators.']);
        }

        try {
            $this->invitations->invite($data['email'], $role, $actor);
        } catch (ApiException $e) {
            return back()->withErrors(['email' => $e->title])->withInput();
        }

        return back()->with('status', 'Invitation sent to '.strtolower($data['email']).'.');
    }

    public function status(Request $request, User $user): RedirectResponse
    {
        $data = $request->validate(['status' => ['required', 'in:active,suspended,deactivated']]);
        $this->admin->setStatus($request->user(), $user, $data['status']);

        return back()->with('status', "Status changed to {$data['status']}.");
    }

    public function roles(Request $request, User $user): RedirectResponse
    {
        $data = $request->validate(['roles' => ['nullable', 'array'], 'roles.*' => ['string', 'exists:roles,name']]);
        $this->admin->setRoles($request->user(), $user, array_values($data['roles'] ?? []));

        return back()->with('status', 'Roles updated.');
    }

    public function revokeSessions(Request $request, User $user): RedirectResponse
    {
        $count = $this->admin->revokeSessions($request->user(), $user);

        return back()->with('status', "Signed out of {$count} session(s).");
    }

    public function resetMfa(Request $request, User $user): RedirectResponse
    {
        $this->admin->resetMfa($request->user(), $user);

        return back()->with('status', 'MFA reset. The user must enrol again at next sign-in.');
    }

    public function accountStatus(Request $request, User $user, EmailAccount $account): RedirectResponse
    {
        abort_unless($account->user_id === $user->id, 404);
        $data = $request->validate(['enabled' => ['required', 'boolean']]);

        $data['enabled'] ? $this->admin->enableAccount($request->user(), $account) : $this->admin->disableAccount($request->user(), $account);

        return back()->with('status', 'Mailbox connection '.($data['enabled'] ? 'enabled' : 'disabled').'.');
    }
}
