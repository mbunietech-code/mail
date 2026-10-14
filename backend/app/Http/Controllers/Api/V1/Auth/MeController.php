<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Auth\TokenService;
use App\Support\Passwords;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class MeController extends Controller
{
    public function __construct(
        private readonly TokenService $tokens,
        private readonly AuditLogger $audit,
    ) {}

    public function show(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return response()->json([
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'roles' => $user->roles->pluck('name')->values(),
            'mfa_enabled' => $user->hasMfaEnabled(),
        ]);
    }

    /** Only the display name is user-editable; any other field is ignored. */
    public function update(Request $request): JsonResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:120']]);

        /** @var User $user */
        $user = $request->user();
        $user->forceFill(['name' => $data['name']])->save();

        return $this->show($request);
    }

    public function changePassword(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $data = $request->validate([
            'current_password' => ['required', 'string', 'max:256'],
            'password' => Passwords::rules($user->email),
        ]);

        if (! Hash::check($data['current_password'], $user->password)) {
            throw ApiException::forbidden('INVALID_CREDENTIALS', 'Your current password is incorrect.');
        }

        $user->forceFill(['password' => $data['password'], 'password_changed_at' => now()])->save();
        $this->tokens->revokeAllForUser($user, $this->tokens->currentFamilyId($user));
        $this->audit->record('auth.password.changed', 'success', $user, 'user', $user->id);

        return response()->json(null, 204);
    }
}
