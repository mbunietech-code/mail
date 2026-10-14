<?php

namespace App\Console\Commands;

use App\Services\Monitoring\SecurityAlerts;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('mbuniemail:security-alerts {--window=10}')]
#[Description('Evaluate audit and operational signals and alert the security owner')]
class SecurityAlertsCommand extends Command
{
    public function handle(SecurityAlerts $alerts): int
    {
        $found = $alerts->evaluate((int) $this->option('window'));
        $sent = $alerts->dispatch($found);
        $this->info(count($found).' condition(s), '.$sent.' alert(s) sent.');

        return self::SUCCESS;
    }
}
