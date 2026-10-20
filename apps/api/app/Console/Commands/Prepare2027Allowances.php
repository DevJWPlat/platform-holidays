<?php

namespace App\Console\Commands;

use App\Models\AllowanceLedgerEntry;
use App\Models\Organisation;
use App\Models\User;
use App\Services\AllowanceService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class Prepare2027Allowances extends Command
{
    protected $signature = 'holidays:prepare-2027-allowances
        {--commit : Save the 2027 allowance baseline and rebuild the year}';

    protected $description = 'Preview/rebuild 2027 Platform holiday allowances from the agreed Timetastic 2026 annual allowances';

    private array $baselines = [
        'Jonny Whittle' => ['annual' => 22, 'increment_year' => 2027],
        'Josh Diamond' => ['annual' => 30, 'increment_year' => 2027],
        'Lydia Thorne' => ['annual' => 22, 'increment_year' => 2027],
        'Neil Buckley' => ['annual' => 30, 'increment_year' => 2027],
        'Sam Mawhinney' => ['annual' => 20, 'increment_year' => 2028],
        'Tom Jones' => ['annual' => 21, 'increment_year' => 2027],
        'Ben Ost' => ['annual' => 22, 'increment_year' => 2027],
        'Christian Smith' => ['annual' => 23, 'increment_year' => 2027],
        'Matt Ainsworth' => ['annual' => 30, 'increment_year' => 2027],
    ];

    public function handle(AllowanceService $allowanceService): int
    {
        $organisation = Organisation::query()->where('slug', 'platform')->firstOrFail();
        $timezone = $organisation->timezone ?: 'Europe/London';
        $anchor = CarbonImmutable::create(2027, 7, 1, 12, 0, 0, $timezone);
        $year = $allowanceService->leaveYearFor($organisation, $anchor);

        $users = User::query()
            ->where('organisation_id', $organisation->id)
            ->where('is_archived', false)
            ->whereIn('name', array_keys($this->baselines))
            ->get()
            ->keyBy('name');

        $rows = [];
        $missing = [];

        foreach ($this->baselines as $name => $setup) {
            $user = $users->get($name);
            if (! $user) {
                $missing[] = $name;
                continue;
            }

            $bookingEntries = AllowanceLedgerEntry::query()
                ->where('user_id', $user->id)
                ->whereDate('leave_year_start', $year['start']->toDateString())
                ->whereIn('entry_type', [
                    AllowanceLedgerEntry::TYPE_BOOKING_DEDUCTION,
                    AllowanceLedgerEntry::TYPE_BOOKING_REVERSAL,
                ])
                ->get();

            $netBookingMinutes = (int) $bookingEntries->sum('minutes');
            $bookedDays = round(
                abs(min(0, $netBookingMinutes)) / AllowanceService::MINUTES_PER_DAY,
                2,
            );

            $annual = (float) $setup['annual'];
            $incrementYear = (int) $setup['increment_year'];
            $incrementDays = 0.0;

            if ($annual < 30 && 2027 >= $incrementYear) {
                $incrementDays = min(1.0, 30.0 - $annual);
            }

            $entitlement = $annual + $incrementDays;
            $expectedRemaining = round($entitlement - $bookedDays, 2);
            $current = $allowanceService->summary($user, $year['start']);

            $rows[] = [
                $name,
                number_format($annual, 0),
                $incrementYear,
                number_format($entitlement, 0),
                number_format($bookedDays, 2),
                number_format((float) $current['remaining_days'], 2),
                number_format($expectedRemaining, 2),
            ];
        }

        $this->newLine();
        $this->info($this->option('commit') ? '2027 allowance rebuild' : '2027 allowance rebuild preview');
        $this->table(
            ['Person','2026 annual','+1 starts','2027 entitlement','2027 booked','Current remaining','Expected remaining'],
            $rows,
        );

        if (count($missing)) {
            $this->error('Missing users: ' . implode(', ', $missing));
            return self::FAILURE;
        }

        if (! $this->option('commit')) {
            $this->comment('PREVIEW ONLY - nothing was changed.');
            $this->line('Expected entitlements before bookings: Jonny 23, Josh 30, Lydia 23, Neil 30, Sam 20, Tom 22, Ben 23, Christian 24, Matt 30.');
            $this->line('When the booked-days column looks right, run: php artisan holidays:prepare-2027-allowances --commit');
            return self::SUCCESS;
        }

        DB::transaction(function () use ($users, $allowanceService, $year, $anchor) {
            foreach ($this->baselines as $name => $setup) {
                $user = $users->get($name);

                $user->forceFill([
                    'holiday_allowance_override_days' => (float) $setup['annual'],
                    'service_increment_start_year' => (int) $setup['increment_year'],
                ])->save();

                AllowanceLedgerEntry::query()
                    ->where('user_id', $user->id)
                    ->whereDate('leave_year_start', $year['start']->toDateString())
                    ->whereIn('source_key', ['base-grant','service-increment','carry-over'])
                    ->delete();

                $allowanceService->ensureYear($user->fresh(), $anchor);
            }
        });

        $this->info('2027 allowances rebuilt.');
        $this->line('Run the command again without --commit to verify the new remaining figures.');
        return self::SUCCESS;
    }
}
