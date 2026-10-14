<?php

namespace App\Http\Controllers\Api\V1\Accounts;

use App\Http\Controllers\Controller;
use App\Mailbox\Gmail\GoogleOAuthService;
use App\Models\User;
use App\Services\Auth\TokenService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class GmailController extends Controller
{
    public function __construct(
        private readonly GoogleOAuthService $oauth,
        private readonly TokenService $tokens,
    ) {}

    public function start(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $family = $this->tokens->currentFamilyId($user);
        abort_if($family === null, 403);

        return response()->json(['authorization_url' => $this->oauth->authorize($user, $family)]);
    }

    /** Public: Google redirects the system browser here. Never returns tokens. */
    public function callback(Request $request): RedirectResponse
    {
        $target = $this->oauth->callback(
            is_string($request->query('code')) ? $request->query('code') : null,
            is_string($request->query('state')) ? $request->query('state') : null,
            is_string($request->query('error')) ? $request->query('error') : null,
        );

        return redirect()->away($target)->header('Referrer-Policy', 'no-referrer');
    }

    public function complete(Request $request): JsonResponse
    {
        $data = $request->validate(['completion' => ['required', 'string', 'size:64', 'alpha_num']]);

        /** @var User $user */
        $user = $request->user();
        $account = $this->oauth->complete($user, $this->tokens->currentFamilyId($user), $data['completion']);

        return response()->json(AccountController::present($account->load('providerProfile')), 201);
    }
}
