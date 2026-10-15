<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ProviderProfile;
use App\Services\Admin\DomainService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use InvalidArgumentException;

/** Company email domains: only these may sign in, be invited or link a mailbox. */
class DomainController extends Controller
{
    public function __construct(private readonly DomainService $domains) {}

    public function index(): View
    {
        return view('admin.domains', [
            'rows' => $this->domains->overview(),
            'servers' => $this->domains->mailServers(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'domain' => ['required', 'string', 'max:253'],
            'server' => ['nullable', 'string', 'exists:provider_profiles,id'],
        ]);
        $server = isset($data['server']) ? ProviderProfile::query()->find($data['server']) : null;

        try {
            $domain = $this->domains->add($data['domain'], $server, $request->user());
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['domain' => $e->getMessage()])->withInput();
        }

        return back()->with('status', "{$domain} added. Employees with @{$domain} addresses can now be invited.");
    }

    public function server(Request $request, string $domain): RedirectResponse
    {
        $data = $request->validate(['server' => ['required', 'string', 'exists:provider_profiles,id']]);

        try {
            $this->domains->setServer($domain, ProviderProfile::query()->findOrFail($data['server']), $request->user());
        } catch (InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('status', "Mail server for {$domain} updated.");
    }

    public function destroy(Request $request, string $domain): RedirectResponse
    {
        $request->validate(['confirm' => ['accepted']], ['confirm.accepted' => 'Tick the box to confirm that employees on this domain will lose access.']);

        try {
            $this->domains->remove($domain, $request->user());
        } catch (InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('status', "{$domain} removed. Its employees can no longer sign in.");
    }
}
