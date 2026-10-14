<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Mailbox\HostGuard;
use App\Mailbox\ProviderError;
use App\Models\ProviderProfile;
use App\Services\Audit\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** Mail server settings. Only admins set hosts; users never can (anti-SSRF, SDD §5.4). */
class ProviderProfileController extends Controller
{
    public function __construct(
        private readonly HostGuard $guard,
        private readonly AuditLogger $audit,
    ) {}

    public function index(): View
    {
        return view('admin.providers', ['profiles' => ProviderProfile::query()->orderBy('key')->get()]);
    }

    public function update(Request $request, ProviderProfile $profile): RedirectResponse
    {
        abort_unless($profile->driver === ProviderProfile::DRIVER_IMAP_SMTP, 404);
        $host = ['required', 'string', 'max:253', 'regex:/^(?=.{1,253}$)([a-z0-9](-?[a-z0-9])*\.)+[a-z]{2,63}$/i'];
        $data = $request->validate([
            'imap_host' => $host, 'imap_port' => ['required', 'integer', 'between:1,65535'], 'imap_encryption' => ['required', 'in:ssl,starttls'],
            'smtp_host' => $host, 'smtp_port' => ['required', 'integer', 'between:1,65535'], 'smtp_encryption' => ['required', 'in:ssl,starttls'],
            'saves_sent_automatically' => ['required', 'boolean'],
            'enabled' => ['required', 'boolean'],
        ]);

        foreach (['imap_host', 'smtp_host'] as $field) {
            try {
                $this->guard->resolve(strtolower($data[$field]), permitDevLoopback: false);
            } catch (ProviderError $e) {
                return back()->withErrors([$field => 'This host is not allowed or cannot be resolved: '.$e->errorCode->remedy()])->withInput();
            }
        }

        $before = $profile->only(['imap_host', 'imap_port', 'imap_encryption', 'smtp_host', 'smtp_port', 'smtp_encryption']);
        $profile->forceFill([...$data, 'imap_host' => strtolower($data['imap_host']), 'smtp_host' => strtolower($data['smtp_host'])])->save();

        $this->audit->record('admin.provider_profile.changed', 'success', $request->user(), 'provider_profile', $profile->id, [
            'imap_before' => "{$before['imap_host']}:{$before['imap_port']}/{$before['imap_encryption']}",
            'imap_after' => "{$profile->imap_host}:{$profile->imap_port}/{$profile->imap_encryption}",
            'smtp_before' => "{$before['smtp_host']}:{$before['smtp_port']}/{$before['smtp_encryption']}",
            'smtp_after' => "{$profile->smtp_host}:{$profile->smtp_port}/{$profile->smtp_encryption}",
        ], 'admin');

        return back()->with('status', 'Mail server settings saved. Existing connections use them from their next sync.');
    }
}
