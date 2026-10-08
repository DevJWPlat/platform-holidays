<?php

namespace Database\Seeders;

use App\Models\LeaveType;
use App\Models\Organisation;
use App\Models\User;
use App\Models\WorkSchedule;
use App\Models\WorkScheduleAssignment;
use Illuminate\Database\Seeder;

class LeavePolicySeeder extends Seeder
{
    public function run(): void
    {
        $organisation = Organisation::query()
            ->where('slug', 'platform')
            ->firstOrFail();

        LeaveType::query()->updateOrCreate(
            [
                'organisation_id' => $organisation->id,
                'key' => LeaveType::HOLIDAY_KEY,
            ],
            [
                'label' => 'Holiday',
                'colour' => '#9FD356',
                'icon' => 'umbrella-beach',
                'is_system' => true,
                'is_protected_holiday' => true,
                'visibility' => 'public',
                'requires_approval' => true,
                'include_in_staffing_limits' => true,
                'external_availability' => 'out_of_office',
                'is_active' => true,
                'display_order' => 10,
            ],
        );

        $schedule = WorkSchedule::query()->updateOrCreate(
            [
                'organisation_id' => $organisation->id,
                'name' => 'Monday to Friday',
            ],
            [
                'pattern_type' => 'weekly',
                'is_default' => true,
                'is_active' => true,
            ],
        );

        $sessions = [];

        for ($weekday = 1; $weekday <= 5; $weekday++) {
            $sessions[] = [
                'weekday' => $weekday,
                'session' => 'morning',
                'starts_at' => '09:00:00',
                'ends_at' => '12:30:00',
                'duration_minutes' => 210,
            ];

            $sessions[] = [
                'weekday' => $weekday,
                'session' => 'afternoon',
                'starts_at' => '13:30:00',
                'ends_at' => '17:00:00',
                'duration_minutes' => 210,
            ];
        }

        foreach ($sessions as $session) {
            $schedule->sessions()->updateOrCreate(
                [
                    'week_number' => 1,
                    'weekday' => $session['weekday'],
                    'session' => $session['session'],
                ],
                [
                    'starts_at' => $session['starts_at'],
                    'ends_at' => $session['ends_at'],
                    'duration_minutes' => $session['duration_minutes'],
                ],
            );
        }

        User::query()
            ->where('organisation_id', $organisation->id)
            ->each(function (User $user) use ($schedule) {
                WorkScheduleAssignment::query()->firstOrCreate(
                    [
                        'user_id' => $user->id,
                        'work_schedule_id' => $schedule->id,
                        'effective_from' => $user->employment_start_date?->toDateString() ?? '2026-01-01',
                    ],
                );
            });
    }
}
