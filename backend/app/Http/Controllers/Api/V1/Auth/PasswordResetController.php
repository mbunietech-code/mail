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
use Illuminate\Support\Facades\Password;

class PasswordResetController extends Controller
{
    public function __construct(
        private readonly TokenService $tokens,
        private readonly AuditLogger $audit,
    ) {}

    /** Always answers identically so the endpoint cannot be used to discover accounts. */
    public function forgot(Request $request): JsonResponse
    {
        $data = $request->validate(['email' => ['required', 'string', 'email', 'max:254']]);
        $email = strtolower(trim($data['email']));

        $user = User::query()->where('email', $email)->first();
        if ($user !== null && $user->isActive()) {
            Password::broker()->sendResetLink(['email' => $email]);
            $this->audit->record('auth.password.reset_requested', 'success', $user, 'user', $user->id);
        }

        return response()->json(['status' => 'if_exists_email_sent'], 202);
    }

    public function reset(Request $request): JsonResponse
    {
        $email = strtolower(trim((string) $request->input('email')));
        $data = $request->validate([
            'email' => ['required', 'string', 'email', 'max:254'],
            'token' => ['required', 'string', 'max:128'],
            'password' => Passwords::rules($email),
        ]);

        $status = Password::broker()->reset(
            ['email' => $email, 'token' => $data['token'], 'password' => $data['password']],
            function (User $user, string $password) {
                $user->forceFill(['password' => $password, 'password_changed_at' => now()])->save();
                $this->tokens->revokeAllForUser($user);
                $this->audit->record('auth.password.reset', 'success', $user, 'user', $user->id);
            },
        );

        if ($status !== Password::PASSWORD_RESET) {
            throw new ApiException('RESET_INVALID', 422, 'This reset link is invalid or has expired.');
        }

        return response()->json(null, 204);
    }
}
