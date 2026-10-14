<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * Seeds reference data only (roles, organisation, provider profiles).
 * It never creates users or mailbox data — production starts empty.
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            RolesAndPermissionsSeeder::class,
            OrganizationSeeder::class,
            ProviderProfileSeeder::class,
        ]);
    }
}
