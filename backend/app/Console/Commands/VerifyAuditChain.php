<?php

namespace App\Console\Commands;

use App\Services\Audit\AuditLogger;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('mbuniemail:audit-verify')]
#[Description('Verify the audit log hash chain has not been altered')]
class VerifyAuditChain extends Command
{
    public function handle(AuditLogger $audit): int
    {
        $broken = $audit->verify();

        if ($broken !== null) {
            $this->error("Audit chain broken at entry #{$broken}. Treat as a security incident (docs/INCIDENT_RESPONSE.md).");

            return self::FAILURE;
        }

        $this->info('Audit chain intact.');

        return self::SUCCESS;
    }
}
