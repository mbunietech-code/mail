<?php

namespace App\Console\Commands;

use App\Models\Organization;
use App\Models\Role;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Support\Passwords;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;

/**
 * Bootstrap the first super administrator from the server console. The password is
 * read with a hidden prompt (never as an argument, so it is not kept in shell history).
 * MFA enrolment is forced at first login because admin roles always require MFA.
 */
#[Signature('mbuniemail:create-admin {email} {name}')]
#[Description('Create a super administrator (password entered interactively)')]
class CreateAdmin extends Command
{
    public function handle(AuditLogger $audit): int
    {
        $email = strtolower(trim((string) $this->argument('email')));
        $org = Organization::query()->first();

        if ($org === null || Role::query()->where('name', Role::SUPER_ADMIN)->doesntExist()) {
            $this->error('Run `php artisan db:seed` first.');

            return self::FAILURE;
        }
        if (! $org->allowsEmail($email)) {
            $this->error('Email domain is not in the allowed domains.');

            return self::FAILURE;
        }
        if (User::query()->where('email', $email)->exists()) {
            $this->error('A user with this email already exists.');

            return self::FAILURE;
        }

        $password = (string) $this->secret('Password');
        $validator = Validator::make(
            ['password' => $password, 'password_confirmation' => (string) $this->secret('Confirm password')],
            ['password' => Passwords::rules($email)],
        );
        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $message) {
                $this->error($message);
            }

            return self::FAILURE;
        }

        $user = new User(['organization_id' => $org->id, 'name' => (string) $this->argument('name'), 'email' => $email, 'password' => $password]);
        $user->forceFill(['status' => User::STATUS_ACTIVE, 'password_changed_at' => now()])->save();
        $user->roles()->attach(Role::query()->where('name', Role::SUPER_ADMIN)->value('id'));
        $audit->record('admin.user.created_via_console', 'success', null, 'user', $user->id, ['role' => Role::SUPER_ADMIN]);

        $this->info("Super administrator {$email} created. MFA enrolment is required at first login.");

        return self::SUCCESS;
    }
}
