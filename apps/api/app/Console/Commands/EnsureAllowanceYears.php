<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\AllowanceService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

class EnsureAllowanceYears extends Command
{
    protected $signature = 'holidays:allowances:ensure {year? : Leave-year start year, e.g. 2026}';

    protected $description = 'Ensure annual holiday allowance ledger grants exist for active users';

    public function handle(AllowanceService $allowanceService): int
    {
        $year = $this->argument('year');

        $skippedUsers = User::query()
            ->where('is_archived', false)
            ->whereNull('organisation_id')
            ->count();

        if ($skippedUsers > 0) {
            $this->warn(sprintf(
                'Skipping %d active user(s) with no organisation assigned.',
                $skippedUsers,
            ));
        }

        $processed = 0;

        User::query()
            ->where('is_archived', false)
            ->whereNotNull('organisation_id')
            ->with('organisation')
            ->whereHas('organisation')
            ->each(function (User $user) use ($allowanceService, $year, &$processed) {
                $timezone = $user->organisation?->timezone ?: 'Europe/London';

                $anchor = $year
                    ? CarbonImmutable::create((int) $year, 7, 1, 0, 0, 0, $timezone)
                    : CarbonImmutable::now($timezone);

                $summary = $allowanceService->ensureYear($user, $anchor);

                $this->line(sprintf(
                    '%s: %.2f days remaining (%s to %s)',
                    $user->name,
                    $summary['remaining_days'],
                    $summary['leave_year']['start'],
                    $summary['leave_year']['end'],
                ));

                $processed++;
            });

        if ($processed === 0) {
            $this->warn('No active users with an organisation were found.');
        } else {
            $this->info(sprintf(
                'Allowance years ensured for %d user(s).',
                $processed,
            ));
        }

        return self::SUCCESS;
    }
}
