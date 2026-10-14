<?php

namespace App\Http\Controllers;

use App\Exceptions\ApiException;
use App\Http\Controllers\Api\V1\Auth\InvitationController;
use App\Http\Controllers\Api\V1\Auth\PasswordResetController;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Browser pages behind the invitation and password-reset email links. They reuse the
 * API controllers, so the rules (single-use hashed tokens, expiry, password policy,
 * session revocation, audit) are identical.
 */
class OnboardingController extends Controller
{
    public function showInvite(): View
    {
        return view('onboarding.invite');
    }

    public function acceptInvite(Request $request, InvitationController $api): RedirectResponse
    {
        try {
            $json = $api->accept($request)->getData(true);
        } catch (ApiException $e) {
            return back()->withErrors(['token' => $e->title])->withInput($request->only('name', 'token'));
        } catch (ValidationException $e) {
            return back()->withErrors($e->errors())->withInput($request->only('name', 'token'));
        }

        return back()->with('done', $json['email'] ?? '');
    }

    public function showReset(): View
    {
        return view('onboarding.reset');
    }

    public function reset(Request $request, PasswordResetController $api): RedirectResponse
    {
        try {
            $api->reset($request);
        } catch (ApiException $e) {
            return back()->withErrors(['token' => $e->title])->withInput($request->only('email', 'token'));
        } catch (ValidationException $e) {
            return back()->withErrors($e->errors())->withInput($request->only('email', 'token'));
        }

        return back()->with('done', true);
    }
}
