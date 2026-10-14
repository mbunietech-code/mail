<?php

use App\Http\Controllers\Api\V1\Accounts\AccountController;
use App\Http\Controllers\Api\V1\Accounts\GmailController;
use App\Http\Controllers\Api\V1\Auth\InvitationController;
use App\Http\Controllers\Api\V1\Auth\LoginController;
use App\Http\Controllers\Api\V1\Auth\MeController;
use App\Http\Controllers\Api\V1\Auth\MfaController;
use App\Http\Controllers\Api\V1\Auth\PasswordResetController;
use App\Http\Controllers\Api\V1\Auth\ReauthController;
use App\Http\Controllers\Api\V1\Auth\SessionController;
use Illuminate\Support\Facades\Route;

/*
| Deny by default: every route requires a Sanctum token with an explicit ability
| unless it is listed in the "public" group below. tests/Feature/Security/RouteSweepTest
| fails if a new route is added outside these groups without being classified.
*/

Route::prefix('v1')->group(function () {

    // ---- Public (rate limited by IP / identifier) -------------------------------
    Route::middleware('throttle:auth-public')->group(function () {
        Route::post('auth/login', [LoginController::class, 'login'])->name('auth.login');
        Route::post('auth/refresh', [LoginController::class, 'refresh'])->name('auth.refresh');
        Route::post('auth/password/forgot', [PasswordResetController::class, 'forgot'])
            ->middleware('throttle:password-reset')->name('auth.password.forgot');
        Route::post('auth/password/reset', [PasswordResetController::class, 'reset'])->name('auth.password.reset');
        Route::post('auth/invitations/accept', [InvitationController::class, 'accept'])->name('auth.invitations.accept');
        Route::get('oauth/google/callback', [GmailController::class, 'callback'])->name('oauth.google.callback');
    });

    Route::middleware(['auth:sanctum', 'throttle:api'])->group(function () {

        // ---- MFA-pending tokens ----------------------------------------------------
        Route::post('auth/mfa/verify', [MfaController::class, 'verify'])
            ->middleware('token:mfa:verify')->name('auth.mfa.verify');

        Route::middleware('token:mail,mfa:enroll')->group(function () {
            Route::post('auth/mfa/enroll', [MfaController::class, 'enroll'])->name('auth.mfa.enroll');
            Route::post('auth/mfa/confirm', [MfaController::class, 'confirm'])->name('auth.mfa.confirm');
        });

        // ---- Full access tokens ----------------------------------------------------
        Route::middleware('token:mail')->group(function () {
            Route::post('auth/logout', [LoginController::class, 'logout'])->name('auth.logout');
            Route::post('auth/reauth', ReauthController::class)->name('auth.reauth');
            Route::get('auth/sessions', [SessionController::class, 'index'])->name('auth.sessions.index');
            Route::delete('auth/sessions/{id}', [SessionController::class, 'destroy'])
                ->where('id', '[0-9A-Za-z]{26}')->name('auth.sessions.destroy');

            Route::get('me', [MeController::class, 'show'])->name('me.show');
            Route::patch('me', [MeController::class, 'update'])->name('me.update');

            Route::get('accounts', [AccountController::class, 'index'])->name('accounts.index');
            Route::prefix('accounts/{id}')->where(['id' => '[0-9A-Za-z]{26}'])->group(function () {
                Route::get('/', [AccountController::class, 'show'])->name('accounts.show');
                Route::patch('/', [AccountController::class, 'update'])->name('accounts.update');
                Route::get('diagnostics', [AccountController::class, 'diagnostics'])->name('accounts.diagnostics');
                Route::get('folders', [AccountController::class, 'folders'])->name('accounts.folders');
            });
            Route::post('accounts/gmail/complete', [GmailController::class, 'complete'])->name('accounts.gmail.complete');

            // ---- Sensitive: require recent re-authentication ----------------------
            Route::middleware('reauth')->group(function () {
                Route::delete('auth/sessions', [SessionController::class, 'destroyOthers'])->name('auth.sessions.destroy-others');
                Route::delete('auth/mfa', [MfaController::class, 'disable'])->name('auth.mfa.disable');
                Route::post('auth/mfa/recovery-codes', [MfaController::class, 'regenerateRecoveryCodes'])->name('auth.mfa.recovery-codes');
                Route::put('me/password', [MeController::class, 'changePassword'])->name('me.password');

                Route::post('accounts', [AccountController::class, 'store'])->name('accounts.store');
                Route::put('accounts/{id}/credential', [AccountController::class, 'updateCredential'])
                    ->where('id', '[0-9A-Za-z]{26}')->name('accounts.credential');
                Route::delete('accounts/{id}', [AccountController::class, 'destroy'])
                    ->where('id', '[0-9A-Za-z]{26}')->name('accounts.destroy');
                Route::post('accounts/gmail/authorize', [GmailController::class, 'start'])->name('accounts.gmail.authorize');
            });
        });
    });
});
