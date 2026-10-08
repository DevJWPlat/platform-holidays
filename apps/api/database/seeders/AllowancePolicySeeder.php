<?php

namespace Database\Seeders;

use App\Models\Organisation;
use App\Models\OrganisationSetting;
use Illuminate\Database\Seeder;

class AllowancePolicySeeder extends Seeder
{
    public function run(): void
    {
        $organisation = Organisation::query()
            ->where('slug', 'platform')
            ->firstOrFail();

        OrganisationSetting::query()->updateOrCreate(
            [
                'organisation_id' => $organisation->id,
                'key' => 'holiday_allowance_policy',
            ],
            [
                'value' => [
                    'base_allowance_days' => 25,
                    'service_increment_enabled' => true,
                    'service_increment_days' => 1,
                    'service_increment_after_years' => 1,
                    'maximum_allowance_days' => null,
                    'carry_over_enabled' => true,
                    'maximum_carry_over_days' => null,
                ],
            ],
        );
    }
}
