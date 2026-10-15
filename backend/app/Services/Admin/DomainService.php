<?php

namespace App\Services\Admin;

use App\Models\EmailAccount;
use App\Models\Organization;
use App\Models\ProviderProfile;
use App\Models\Role;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Support\CompanyDomains;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * The organisation's email domains. A domain in this list is the only way an
 * address can sign in, be invited, or have a mailbox linked; each domain is
 * served by one mail-server profile (ProviderProfile::$domains).
 */
class DomainService
{
    public const MAX_DOMAINS = 50;

    public function __construct(private readonly AuditLogger $audit) {}

    public static function normalise(string $domain): string
    {
        return strtolower(trim(ltrim(trim($domain), '@'), " .\t\n\r"));
    }

    public static function isValid(string $domain): bool
    {
        return preg_match('/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+(?:[a-z]{2,63}|xn--[a-z0-9-]{1,59})$/', $domain) === 1;
    }

    /**
     * One row per domain, with what depends on it.
     *
     * @return list<array{domain: string, users: int, active_users: int, mailboxes: int, server: ProviderProfile|null}>
     */
    public function overview(): array
    {
        $domains = CompanyDomains::all();
        $users = User::query()->whereIn('email_domain', $domains)->groupBy('email_domain')
            ->selectRaw('email_domain, COUNT(*) AS total, SUM(status = ?) AS active', [User::STATUS_ACTIVE])->get()->keyBy('email_domain');
        $mailboxes = EmailAccount::query()->whereIn('email_domain', $domains)->groupBy('email_domain')
            ->selectRaw('email_domain, COUNT(*) AS total')->pluck('total', 'email_domain');
        $profiles = $this->mailServers();

        return array_map(fn (string $d) => [
            'domain' => $d,
            'users' => (int) ($users[$d]->total ?? 0),
            'active_users' => (int) ($users[$d]->active ?? 0),
            'mailboxes' => (int) ($mailboxes[$d] ?? 0),
            'server' => $profiles->first(fn (ProviderProfile $p) => in_array($d, array_map('strtolower', $p->domains), true)),
        ], $domains);
    }

    /** @return Collection<int, ProviderProfile> */
    public function mailServers()
    {
        return ProviderProfile::query()->where('driver', ProviderProfile::DRIVER_IMAP_SMTP)->orderBy('label')->get();
    }

    public function add(string $domain, ?ProviderProfile $server, ?User $actor): string
    {
        $domain = self::normalise($domain);
        if (! self::isValid($domain)) {
            throw new InvalidArgumentException("“{$domain}” is not a valid domain name (example: mbuniehub.com).");
        }

        DB::transaction(function () use ($domain, $server) {
            $org = Organization::query()->lockForUpdate()->firstOrFail();
            $list = array_map('strtolower', $org->allowed_domains);
            if (in_array($domain, $list, true)) {
                throw new InvalidArgumentException("{$domain} is already a company domain.");
            }
            if (count($list) >= self::MAX_DOMAINS) {
                throw new InvalidArgumentException('The maximum of '.self::MAX_DOMAINS.' domains has been reached.');
            }
            $org->forceFill(['allowed_domains' => [...$list, $domain]])->save();
            if ($server !== null) {
                $this->assignServer($domain, $server);
            }
        });
        CompanyDomains::forget();

        $this->audit->record('admin.domain.added', 'success', $actor, context: ['domain' => $domain, 'server' => $server?->key], actorType: $actor ? 'admin' : 'system');

        return $domain;
    }

    public function setServer(string $domain, ProviderProfile $server, ?User $actor): void
    {
        $domain = self::normalise($domain);
        if (! in_array($domain, CompanyDomains::all(), true)) {
            throw new InvalidArgumentException("{$domain} is not a company domain.");
        }
        DB::transaction(fn () => $this->assignServer($domain, $server));

        $this->audit->record('admin.domain.server_changed', 'success', $actor, context: ['domain' => $domain, 'server' => $server->key], actorType: $actor ? 'admin' : 'system');
    }

    /**
     * Removing a domain locks its employees out immediately (sign-in, refresh, API,
     * sync, queued sends) — see User::isActive(). Guards prevent locking out the
     * administrator doing it, or leaving no domain at all.
     */
    public function remove(string $domain, ?User $actor): void
    {
        $domain = self::normalise($domain);

        DB::transaction(function () use ($domain, $actor) {
            $org = Organization::query()->lockForUpdate()->firstOrFail();
            $list = array_map('strtolower', $org->allowed_domains);
            if (! in_array($domain, $list, true)) {
                throw new InvalidArgumentException("{$domain} is not a company domain.");
            }
            if (count($list) === 1) {
                throw new InvalidArgumentException('At least one company domain is required.');
            }
            if ($actor !== null && CompanyDomains::domainOf($actor->email) === $domain) {
                throw new InvalidArgumentException("You cannot remove {$domain}: your own account uses it and you would be locked out.");
            }
            $superAdmins = fn (array $domains) => User::query()->where('status', User::STATUS_ACTIVE)->whereIn('email_domain', $domains)
                ->whereHas('roles', fn ($q) => $q->where('name', Role::SUPER_ADMIN))->exists();
            if ($superAdmins($list) && ! $superAdmins(array_values(array_diff($list, [$domain])))) {
                throw new InvalidArgumentException("You cannot remove {$domain}: no active super administrator would remain on the other domains.");
            }

            $org->forceFill(['allowed_domains' => array_values(array_diff($list, [$domain]))])->save();
            foreach (ProviderProfile::query()->lockForUpdate()->get() as $profile) {
                if (in_array($domain, array_map('strtolower', $profile->domains), true)) {
                    $profile->forceFill(['domains' => array_values(array_diff(array_map('strtolower', $profile->domains), [$domain]))])->save();
                }
            }
        });
        CompanyDomains::forget();

        $this->audit->record('admin.domain.removed', 'success', $actor, context: ['domain' => $domain], actorType: $actor ? 'admin' : 'system');
    }

    private function assignServer(string $domain, ProviderProfile $server): void
    {
        foreach (ProviderProfile::query()->lockForUpdate()->get() as $profile) {
            $domains = array_map('strtolower', $profile->domains);
            $has = in_array($domain, $domains, true);
            if ($profile->is($server) && ! $has) {
                $profile->forceFill(['domains' => [...$domains, $domain]])->save();
            } elseif (! $profile->is($server) && $has) {
                $profile->forceFill(['domains' => array_values(array_diff($domains, [$domain]))])->save();
            }
        }
    }
}
