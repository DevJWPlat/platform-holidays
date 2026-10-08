<?php

namespace Database\Seeders;

use App\Models\Organisation;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DevelopmentUserSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            return;
        }

        $organisation = Organisation::query()
            ->where('slug', 'platform')
            ->firstOrFail();

        User::query()->updateOrCreate(
            ['email' => 'jonny@platform.team'],
            [
                'organisation_id' => $organisation->id,
                'name' => 'Jonny Whittle',
                'password' => Hash::make('password'),
                'job_title' => 'Front-end developer',
                'role' => 'administrator',
                'allowance_unit' => 'days',
                'bank_holiday_division' => 'england-and-wales',
                'is_archived' => false,
                'email_verified_at' => now(),
            ],
        );
    }
}
