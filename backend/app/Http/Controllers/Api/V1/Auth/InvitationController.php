<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\Invitation;
use App\Models\Organization;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Support\CompanyDomains;
use App\Support\Passwords;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class InvitationController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function accept(Request $request): JsonResponse
    {
        $request->validate(['token' => ['required', 'string', 'max:128']]);

        $invitation = Invitation::query()->where('token_hash', hash('sha256', (string) $request->input('token')))->first();
        if ($invitation === null || ! $invitation->isPending()) {
            throw new ApiException('INVITATION_INVALID', 422, 'This invitation is invalid or has expired.');
        }
        if (! CompanyDomains::contains($invitation->email)) {
            throw ApiException::forbidden('DOMAIN_NOT_ALLOWED', 'This email domain is no longer allowed. Contact your administrator.');
        }

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'password' => Passwords::rules($invitation->email),
        ]);

        $user = DB::transaction(function () use ($invitation, $data) {
            $locked = Invitation::query()->lockForUpdate()->findOrFail($invitation->id);
            if (! $locked->isPending() || User::query()->where('email', $locked->email)->exists()) {
                throw new ApiException('INVITATION_INVALID', 422, 'This invitation is invalid or has expired.');
            }

            $user = new User([
                'organization_id' => Organization::query()->valueOrFail('id'),
                'name' => $data['name'],
                'email' => $locked->email,
                'password' => $data['password'],
            ]);
            $user->forceFill(['status' => User::STATUS_ACTIVE, 'password_changed_at' => now()])->save();
            $user->roles()->attach($locked->role_id);
            $locked->forceFill(['accepted_at' => now()])->save();

            return $user;
        });

        $this->audit->record('auth.invitation.accepted', 'success', $user, 'invitation', $invitation->id);

        return response()->json(['status' => 'account_created', 'email' => $user->email], 201);
    }
}
