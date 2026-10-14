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
use App\Http\Controllers\Api\V1\Mail\AttachmentController;
use App\Http\Controllers\Api\V1\Mail\ContactController;
use App\Http\Controllers\Api\V1\Mail\FolderController;
use App\Http\Controllers\Api\V1\Mail\ImageProxyController;
use App\Http\Controllers\Api\V1\Mail\MessageController;
use App\Http\Controllers\Api\V1\Mail\OutboxController;
use App\Http\Controllers\Api\V1\Mail\PreferenceController;
use App\Http\Controllers\Api\V1\Mail\SearchController;
use App\Http\Controllers\Api\V1\Mail\SyncController;
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

            // ---- Mail ----------------------------------------------------------------
            $ulid = '[0-9A-Za-z]{26}';
            Route::post('accounts/{id}/folders', [FolderController::class, 'store'])->where('id', $ulid)->name('folders.store');
            Route::get('accounts/{id}/search', SearchController::class)->where('id', $ulid)->name('accounts.search');
            Route::post('accounts/{id}/sync', [SyncController::class, 'trigger'])->where('id', $ulid)->name('accounts.sync');
            Route::get('sync/status', [SyncController::class, 'status'])->name('sync.status');

            Route::get('folders/{id}/messages', [MessageController::class, 'index'])->where('id', $ulid)->name('messages.index');
            Route::post('messages/bulk', [MessageController::class, 'bulk'])->name('messages.bulk');
            Route::prefix('messages/{id}')->where(['id' => $ulid])->group(function () {
                Route::get('/', [MessageController::class, 'show'])->name('messages.show');
                Route::patch('/', [MessageController::class, 'update'])->name('messages.update');
                Route::delete('/', [MessageController::class, 'destroy'])->name('messages.destroy');
                Route::post('move', [MessageController::class, 'move'])->name('messages.move');
                Route::post('spam', [MessageController::class, 'spam'])->name('messages.spam');
            });
            Route::get('threads/{key}', [MessageController::class, 'thread'])->where('key', $ulid)->name('threads.show');
            Route::get('attachments/{id}', [AttachmentController::class, 'show'])->where('id', $ulid)->name('attachments.show');
            Route::get('image-proxy', ImageProxyController::class)->middleware('throttle:image-proxy')->name('image-proxy');

            Route::post('outbox/send', [OutboxController::class, 'send'])->name('outbox.send');
            Route::get('outbox/{id}', [OutboxController::class, 'show'])->where('id', $ulid)->name('outbox.show');
            Route::post('outbox/{id}/resend', [OutboxController::class, 'resend'])->where('id', $ulid)->name('outbox.resend');
            Route::post('outbox/attachments', [OutboxController::class, 'uploadAttachment'])->middleware('throttle:uploads')->name('outbox.attachments.store');
            Route::delete('outbox/attachments/{id}', [OutboxController::class, 'deleteAttachment'])->where('id', $ulid)->name('outbox.attachments.destroy');
            Route::get('drafts', [OutboxController::class, 'drafts'])->name('drafts.index');
            Route::put('drafts/{id}', [OutboxController::class, 'saveDraft'])->where('id', $ulid)->name('drafts.save');
            Route::delete('drafts/{id}', [OutboxController::class, 'deleteDraft'])->where('id', $ulid)->name('drafts.destroy');

            Route::get('contacts/suggest', [ContactController::class, 'suggest'])->name('contacts.suggest');
            Route::get('me/preferences', [PreferenceController::class, 'show'])->name('preferences.show');
            Route::patch('me/preferences', [PreferenceController::class, 'update'])->name('preferences.update');

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
