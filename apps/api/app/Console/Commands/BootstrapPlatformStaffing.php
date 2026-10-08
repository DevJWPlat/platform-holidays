<?php

namespace App\Console\Commands;

use App\Models\Department;
use App\Models\Organisation;
use App\Models\StaffingGroup;
use App\Models\User;
use Illuminate\Console\Command;

class BootstrapPlatformStaffing extends Command
{
    protected $signature = 'holidays:staffing:bootstrap';

    protected $description = 'Create Platform departments, memberships, staffing limits and leadership cover rules.';

    public function handle(): int
    {
        $organisation = Organisation::query()
            ->where('slug', 'platform')
            ->first();

        if (! $organisation) {
            $this->error('Platform organisation was not found.');
            return self::FAILURE;
        }

        $departments = [
            'Front End' => [
                'slug' => 'front-end',
                'colour' => '#EF5B3F',
                'maximum_absent' => 2,
                'members' => ['Jonny Whittle', 'Matt Ainsworth', 'Christian Smith'],
            ],
            'Back End' => [
                'slug' => 'back-end',
                'colour' => '#7A8CFF',
                'maximum_absent' => 2,
                'members' => ['Neil Buckley', 'Tom Jones', 'Sam Mawhinney'],
            ],
            'Design' => [
                'slug' => 'design',
                'colour' => '#C58BFF',
                'maximum_absent' => null,
                'members' => ['Ben Ost'],
            ],
            'Account Management' => [
                'slug' => 'account-management',
                'colour' => '#E6BD67',
                'maximum_absent' => null,
                'members' => ['Lydia Thorne'],
            ],
        ];

        foreach ($departments as $name => $config) {
            $department = Department::query()->updateOrCreate(
                [
                    'organisation_id' => $organisation->id,
                    'slug' => $config['slug'],
                ],
                [
                    'name' => $name,
                    'colour' => $config['colour'],
                    'maximum_absent' => $config['maximum_absent'],
                    'is_active' => true,
                ],
            );

            foreach ($config['members'] as $personName) {
                $person = User::query()
                    ->where('organisation_id', $organisation->id)
                    ->where('name', $personName)
                    ->first();

                if (! $person) {
                    $this->warn("Missing user: {$personName}");
                    continue;
                }

                $person->departments()->syncWithoutDetaching([
                    $department->id => [
                        'is_primary' => true,
                        'is_manager' => false,
                    ],
                ]);

                $this->line("{$personName} → {$name}");
            }
        }

        $leadership = StaffingGroup::query()->updateOrCreate(
            [
                'organisation_id' => $organisation->id,
                'slug' => 'leadership-cover',
            ],
            [
                'name' => 'Leadership cover',
                'maximum_absent' => 1,
                'is_active' => true,
            ],
        );

        $leadershipIds = User::query()
            ->where('organisation_id', $organisation->id)
            ->whereIn('name', ['Josh Diamond', 'Lydia Thorne'])
            ->pluck('id')
            ->all();

        $leadership->users()->sync($leadershipIds);

        User::query()
            ->where('organisation_id', $organisation->id)
            ->whereIn('name', ['Josh Diamond', 'Lydia Thorne'])
            ->update(['can_override_staffing_limits' => true]);

        $this->info('Platform staffing rules configured.');
        $this->line('Front End: max 2 away');
        $this->line('Back End: max 2 away');
        $this->line('Design: no limit');
        $this->line('Account Management: no limit');
        $this->line('Leadership cover: Josh + Lydia, max 1 away');
        $this->line('Josh + Lydia can override conflicts with a reason.');

        return self::SUCCESS;
    }
}
