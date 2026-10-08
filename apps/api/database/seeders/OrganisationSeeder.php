<?php

namespace Database\Seeders;

use App\Models\Organisation;
use Illuminate\Database\Seeder;

class OrganisationSeeder extends Seeder
{
    public function run(): void
    {
        Organisation::query()->updateOrCreate(
            ['slug' => 'platform'],
            [
                'name' => 'Platform',
                'timezone' => 'Europe/London',
                'leave_year_start_month' => 1,
                'leave_year_start_day' => 1,
                'default_bank_holiday_division' => 'england-and-wales',
                'is_active' => true,
            ],
        );
    }
}
