<?php

namespace Database\Seeders;

use App\Models\Organization;
use Illuminate\Database\Seeder;

class OrganizationSeeder extends Seeder
{
    public function run(): void
    {
        $org = Organization::query()->firstOrNew();
        $org->fill([
            'name' => config('mbuniemail.organization_name'),
            'allowed_domains' => config('mbuniemail.allowed_domains'),
        ])->save();
    }
}
