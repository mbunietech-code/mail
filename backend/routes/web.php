<?php

use App\Http\Controllers\Admin\AuditController;
use App\Http\Controllers\Admin\AuthController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\DomainController;
use App\Http\Controllers\Admin\JobController;
use App\Http\Controllers\Admin\PolicyController;
use App\Http\Controllers\Admin\ProviderProfileController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\AppDownloadController;
use App\Http\Controllers\OnboardingController;
use App\Http\Middleware\Admin\EnsureAdminSession;
use App\Http\Middleware\Admin\RequireAdminReauth;
use Illuminate\Support\Facades\Route;

/*
| Admin panel (SRS §5.9). Session + CSRF + MFA. No route here exposes mail content.
| Writes require re-authentication within the last 10 minutes.
*/

Route::redirect('/', '/admin');

// App downloads for employees: the newest uploaded APK, and what the apps check to update themselves.
Route::get('apps/apk', [AppDownloadController::class, 'latestApk'])->name('apps.apk');
Route::get('apps/windows', [AppDownloadController::class, 'latestWindowsInstaller'])->name('apps.windows');
Route::get('apps/latest.json', [AppDownloadController::class, 'manifest'])->name('apps.manifest');

// Email links (invitation, password reset). Tokens travel in the URL fragment.
Route::middleware('throttle:auth-public')->prefix('app')->name('onboarding.')->group(function () {
    Route::get('invite', [OnboardingController::class, 'showInvite'])->name('invite');
    Route::post('invite', [OnboardingController::class, 'acceptInvite'])->name('invite.accept');
    Route::get('reset-password', [OnboardingController::class, 'showReset'])->name('reset');
    Route::post('reset-password', [OnboardingController::class, 'reset'])->name('reset.submit');
});

Route::prefix('admin')->name('admin.')->group(function () {
    Route::middleware('guest:web')->group(function () {
        Route::get('login', [AuthController::class, 'showLogin'])->name('login');
        Route::post('login', [AuthController::class, 'login'])->middleware('throttle:auth-public')->name('login.submit');
    });

    Route::middleware('auth:web')->group(function () {
        Route::get('mfa', [AuthController::class, 'showMfa'])->name('mfa');
        Route::post('mfa', [AuthController::class, 'verifyMfa'])->name('mfa.verify');
        Route::get('mfa/enroll', [AuthController::class, 'showEnroll'])->name('mfa.enroll');
        Route::post('mfa/enroll', [AuthController::class, 'confirmEnroll'])->name('mfa.enroll.confirm');
        Route::post('logout', [AuthController::class, 'logout'])->name('logout');
    });

    Route::middleware(['auth:web', EnsureAdminSession::class])->group(function () {
        Route::get('/', DashboardController::class)->middleware('can:health.read')->name('dashboard');
        Route::get('reauth', [AuthController::class, 'showReauth'])->name('reauth');
        Route::post('reauth', [AuthController::class, 'reauth'])->name('reauth.submit');

        Route::middleware('can:users.manage')->group(function () {
            Route::get('users', [UserController::class, 'index'])->name('users.index');
            Route::get('users/{user}', [UserController::class, 'show'])->name('users.show');
            Route::middleware(RequireAdminReauth::class)->group(function () {
                Route::post('invitations', [UserController::class, 'invite'])->name('invitations.store');
                Route::post('users/{user}/status', [UserController::class, 'status'])->name('users.status');
                Route::post('users/{user}/roles', [UserController::class, 'roles'])->name('users.roles');
                Route::post('users/{user}/sessions/revoke', [UserController::class, 'revokeSessions'])->name('users.sessions.revoke');
                Route::post('users/{user}/mfa/reset', [UserController::class, 'resetMfa'])->name('users.mfa.reset');
                Route::post('users/{user}/accounts/{account}', [UserController::class, 'accountStatus'])->name('users.accounts.status');
            });
        });

        Route::middleware('can:audit.read')->group(function () {
            Route::get('audit', [AuditController::class, 'index'])->name('audit.index');
            Route::get('audit/export', [AuditController::class, 'export'])->middleware(RequireAdminReauth::class)->name('audit.export');
            Route::post('audit/verify', [AuditController::class, 'verify'])->name('audit.verify');
        });

        Route::middleware('can:policies.manage')->group(function () {
            Route::get('policies', [PolicyController::class, 'show'])->name('policies.show');
            Route::post('policies', [PolicyController::class, 'update'])->middleware(RequireAdminReauth::class)->name('policies.update');
            Route::get('domains', [DomainController::class, 'index'])->name('domains.index');
            Route::middleware(RequireAdminReauth::class)->group(function () {
                $domain = '[a-z0-9.-]{3,253}';
                Route::post('domains', [DomainController::class, 'store'])->name('domains.store');
                Route::post('domains/{domain}/server', [DomainController::class, 'server'])->where('domain', $domain)->name('domains.server');
                Route::post('domains/{domain}/remove', [DomainController::class, 'destroy'])->where('domain', $domain)->name('domains.destroy');
            });
            Route::get('providers', [ProviderProfileController::class, 'index'])->name('providers.index');
            Route::post('providers/{profile}', [ProviderProfileController::class, 'update'])->middleware(RequireAdminReauth::class)->name('providers.update');
        });

        Route::middleware('can:jobs.manage')->group(function () {
            Route::get('jobs', [JobController::class, 'index'])->name('jobs.index');
            Route::post('jobs/{uuid}/retry', [JobController::class, 'retry'])->middleware(RequireAdminReauth::class)->name('jobs.retry');
            Route::post('jobs/{uuid}/forget', [JobController::class, 'forget'])->middleware(RequireAdminReauth::class)->name('jobs.forget');
        });
    });
});
