<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Services\Audit\AuditLogger;
use App\Services\Settings;
use App\Support\CompanyDomains;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** Application-wide security settings (FR-ADM-004, FR-ADM-008). */
class PolicyController extends Controller
{
    public function __construct(
        private readonly Settings $settings,
        private readonly AuditLogger $audit,
    ) {}

    public function show(): View
    {
        return view('admin.policies', [
            'mfaRequired' => (bool) $this->settings->get('mfa.required_for_all', config('mbuniemail.mfa.required_for_all')),
            'riskyPolicy' => (string) $this->settings->get('outbox.risky_extension_policy', config('mbuniemail.outbox.risky_extension_policy')),
            'domains' => CompanyDomains::all(),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'mfa_required_for_all' => ['required', 'boolean'],
            'risky_extension_policy' => ['required', 'in:warn,block'],
            'domains' => ['required', 'string', 'max:500'],
        ]);

        $domains = array_values(array_unique(array_filter(array_map(fn ($d) => strtolower(trim($d)), preg_split('/[\s,]+/', $data['domains']) ?: []))));
        foreach ($domains as $domain) {
            if (! preg_match('/^(?=.{1,253}$)([a-z0-9](-?[a-z0-9])*\.)+[a-z]{2,63}$/', $domain)) {
                return back()->withErrors(['domains' => "“{$domain}” is not a valid domain."])->withInput();
            }
        }
        if ($domains === []) {
            return back()->withErrors(['domains' => 'At least one domain is required.'])->withInput();
        }

        $before = [
            'mfa_required_for_all' => (bool) $this->settings->get('mfa.required_for_all', config('mbuniemail.mfa.required_for_all')),
            'risky_extension_policy' => (string) $this->settings->get('outbox.risky_extension_policy', config('mbuniemail.outbox.risky_extension_policy')),
            'domains' => implode(',', CompanyDomains::all()),
        ];

        $actor = $request->user()?->id;
        $this->settings->set('mfa.required_for_all', (bool) $data['mfa_required_for_all'], $actor);
        $this->settings->set('outbox.risky_extension_policy', $data['risky_extension_policy'], $actor);
        Organization::query()->firstOrFail()->forceFill(['allowed_domains' => $domains])->save();
        CompanyDomains::forget();

        $this->audit->record('admin.policy.changed', 'success', $request->user(), context: [
            'mfa_before' => $before['mfa_required_for_all'], 'mfa_after' => (bool) $data['mfa_required_for_all'],
            'attachments_before' => $before['risky_extension_policy'], 'attachments_after' => $data['risky_extension_policy'],
            'domains_before' => $before['domains'], 'domains_after' => implode(',', $domains),
        ], actorType: 'admin');

        return back()->with('status', 'Policies saved.');
    }
}
