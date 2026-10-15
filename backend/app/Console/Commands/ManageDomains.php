<?php

namespace App\Console\Commands;

use App\Models\ProviderProfile;
use App\Services\Admin\DomainService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use InvalidArgumentException;

/** Server-side domain management, also the recovery path if the admin panel is unreachable. */
#[Signature('mbuniemail:domains {action=list : list, add or remove} {domain? : e.g. mbuniehub.com} {--server=hostinger : mail-server profile key for add}')]
#[Description('List, add or remove company email domains')]
class ManageDomains extends Command
{
    public function handle(DomainService $domains): int
    {
        $action = (string) $this->argument('action');
        $domain = (string) $this->argument('domain');

        try {
            switch ($action) {
                case 'list':
                    $this->table(['Domain', 'Employees (active)', 'Mailboxes', 'Mail server'], array_map(fn ($r) => [
                        $r['domain'], "{$r['users']} ({$r['active_users']})", $r['mailboxes'], $r['server'] === null ? '— none —' : $r['server']->label,
                    ], $domains->overview()));
                    break;
                case 'add':
                    $server = ProviderProfile::query()->where('key', (string) $this->option('server'))->first()
                        ?? throw new InvalidArgumentException('Unknown mail-server profile: '.$this->option('server'));
                    $this->info('Added '.$domains->add($domain, $server, null));
                    break;
                case 'remove':
                    if ($this->confirm("Employees on {$domain} will lose access immediately. Continue?")) {
                        $domains->remove($domain, null);
                        $this->info("Removed {$domain}");
                    }
                    break;
                default:
                    throw new InvalidArgumentException('Action must be list, add or remove.');
            }
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
