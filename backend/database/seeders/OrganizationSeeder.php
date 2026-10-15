<?php

namespace Database\Seeders;

use App\Models\Organization;
use Illuminate\Database\Seeder;

class OrganizationSeeder extends Seeder
{
    public function run(): void
    {
        $org = Organization::query()->firstOrNew();
        $org->fill(['name' => config('mbuniemail.organization_name')]);
        // Seed domains once; afterwards administrators manage them (admin → Domains).
        if (! $org->exists) {
            $org->allowed_domains = config('mbuniemail.allowed_domains');
        }
        $org->save();
    }
}
