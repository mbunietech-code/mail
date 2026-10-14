<?php

namespace App\Console\Commands;

use App\Exceptions\ApiException;
use App\Models\Role;
use App\Services\Auth\InvitationService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('mbuniemail:invite {email} {--role=employee}')]
#[Description('Email a single-use invitation to a new employee')]
class InviteUser extends Command
{
    public function handle(InvitationService $invitations): int
    {
        $role = Role::query()->where('name', (string) $this->option('role'))->first();
        if ($role === null) {
            $this->error('Unknown role. Available: '.Role::query()->pluck('name')->implode(', '));

            return self::FAILURE;
        }

        try {
            $invitation = $invitations->invite((string) $this->argument('email'), $role, null);
        } catch (ApiException $e) {
            $this->error($e->title);

            return self::FAILURE;
        }

        $this->info("Invitation sent to {$invitation->email}; expires {$invitation->expires_at->toDateTimeString()} UTC.");

        return self::SUCCESS;
    }
}
