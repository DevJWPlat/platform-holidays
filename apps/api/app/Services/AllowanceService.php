<?php

namespace App\Services;

use App\Models\AllowanceLedgerEntry;
use App\Models\Organisation;
use App\Models\OrganisationSetting;
use App\Models\User;
use Carbon\CarbonImmutable;

class AllowanceService
{
    public const MINUTES_PER_DAY = 420;

    public function leaveYearFor(Organisation $organisation, CarbonImmutable $date): array
    {
        $month = (int) $organisation->leave_year_start_month;
        $day = (int) $organisation->leave_year_start_day;

        $candidate = CarbonImmutable::create(
            $date->year,
            $month,
            $day,
            0,
            0,
            0,
            $organisation->timezone ?: 'Europe/London',
        )->startOfDay();

        $start = $date->startOfDay()->lessThan($candidate)
            ? $candidate->subYear()
            : $candidate;

        return [
            'start' => $start,
            'end' => $start->addYear()->subDay(),
        ];
    }

    public function policy(Organisation $organisation): array
    {
        $setting = OrganisationSetting::query()
            ->where('organisation_id', $organisation->id)
            ->where('key', 'holiday_allowance_policy')
            ->first();

        $value = is_array($setting?->value) ? $setting->value : [];

        return array_merge([
            'base_allowance_days' => 25,
            'service_increment_enabled' => true,
            'service_increment_days' => 1,
            'service_increment_after_years' => 1,
            'maximum_allowance_days' => 30,
            'carry_over_enabled' => true,
            'maximum_carry_over_days' => null,
        ], $value);
    }

    public function ensureYear(User $user, CarbonImmutable $anchor): array
    {
        $organisation = $user->organisation()->firstOrFail();
        $year = $this->leaveYearFor($organisation, $anchor);
        $policy = $this->policy($organisation);

        $automaticMaximumDays = 30.0;

        $contractedAnnualDays = $user->holiday_allowance_override_days !== null
            ? (float) $user->holiday_allowance_override_days
            : (float) $policy['base_allowance_days'];

        $baseAllowanceDays = $contractedAnnualDays;
        $serviceIncrementDays = 0.0;
        $serviceIncrementYears = 0;
        $firstIncrementYear = null;
        $isNewStarterYear = false;
        $newStarterCalendarDays = null;

        $serviceDate = $user->employment_start_date
            ? CarbonImmutable::parse(
                $user->employment_start_date->toDateString(),
                $organisation->timezone ?: 'Europe/London',
            )->startOfDay()
            : null;

        if (
            $serviceDate
            && $serviceDate->greaterThanOrEqualTo($year['start'])
            && $serviceDate->lessThanOrEqualTo($year['end'])
        ) {
            $isNewStarterYear = true;
            $newStarterCalendarDays = $serviceDate->diffInDays($year['end']) + 1;

            $calculatedNewStarterDays = (float) ceil(
                ($contractedAnnualDays / 365) * $newStarterCalendarDays,
            );

            $baseAllowanceDays = $user->new_starter_allowance_override_days !== null
                ? (float) $user->new_starter_allowance_override_days
                : $calculatedNewStarterDays;
        }

        if (
            ! $isNewStarterYear
            && (bool) $policy['service_increment_enabled']
            && $serviceDate
        ) {
            $firstIncrementYear = $user->service_increment_start_year !== null
                ? (int) $user->service_increment_start_year
                : ((int) $serviceDate->year + max(
                    1,
                    (int) ($policy['service_increment_after_years'] ?? 1),
                ));

            $leaveYearNumber = (int) $year['start']->year;

            if ($leaveYearNumber >= $firstIncrementYear) {
                $serviceIncrementYears = ($leaveYearNumber - $firstIncrementYear) + 1;
                $serviceIncrementDays =
                    $serviceIncrementYears * (float) $policy['service_increment_days'];
            }
        }

        // Manual/custom annual entitlement can exceed 30.
        // The automatic yearly +1 cannot take automatic entitlement above 30.
        if ($contractedAnnualDays >= $automaticMaximumDays) {
            $serviceIncrementDays = 0.0;
        } else {
            $serviceIncrementDays = min(
                $serviceIncrementDays,
                max(0.0, $automaticMaximumDays - $baseAllowanceDays),
            );
        }

        $baseMinutes = $this->daysToMinutes($baseAllowanceDays);
        $serviceMinutes = $this->daysToMinutes($serviceIncrementDays);

        $this->upsertSystemEntry(
            user: $user,
            year: $year,
            type: AllowanceLedgerEntry::TYPE_BASE_GRANT,
            minutes: $baseMinutes,
            sourceKey: 'base-grant',
            note: $isNewStarterYear
                ? 'Pro-rata new starter holiday allowance'
                : 'Annual holiday allowance',
            metadata: [
                'base_allowance_days' => $baseAllowanceDays,
                'contracted_annual_days' => $contractedAnnualDays,
                'organisation_default_days' => (float) $policy['base_allowance_days'],
                'uses_person_override' => $user->holiday_allowance_override_days !== null,
                'is_new_starter_year' => $isNewStarterYear,
                'new_starter_calendar_days' => $newStarterCalendarDays,
                'new_starter_allowance_override_days' =>
                    $user->new_starter_allowance_override_days !== null
                        ? (float) $user->new_starter_allowance_override_days
                        : null,
                'new_starter_formula' => $isNewStarterYear
                    ? sprintf(
                        'ceil((%s / 365) * %d)',
                        $contractedAnnualDays,
                        $newStarterCalendarDays,
                    )
                    : null,
            ],
        );

        if ($serviceMinutes > 0) {
            $this->upsertSystemEntry(
                user: $user,
                year: $year,
                type: AllowanceLedgerEntry::TYPE_SERVICE_INCREMENT,
                minutes: $serviceMinutes,
                sourceKey: 'service-increment',
                note: 'Annual service allowance increase',
                metadata: [
                    'first_increment_year' => $firstIncrementYear,
                    'increment_years' => $serviceIncrementYears,
                    'service_increment_days_per_year' =>
                        (float) $policy['service_increment_days'],
                    'service_increment_days_total' => $serviceIncrementDays,
                    'automatic_maximum_days' => $automaticMaximumDays,
                ],
            );
        } else {
            AllowanceLedgerEntry::query()
                ->where('user_id', $user->id)
                ->whereDate('leave_year_start', $year['start']->toDateString())
                ->where('source_key', 'service-increment')
                ->delete();
        }

        $this->ensureCarryOver($user, $organisation, $year, $policy);

        return $this->summary($user, $year['start']);
    }

    public function summary(User $user, CarbonImmutable $anchor): array
    {
        $organisation = $user->organisation()->firstOrFail();
        $year = $this->leaveYearFor($organisation, $anchor);

        $entries = AllowanceLedgerEntry::query()
            ->where('user_id', $user->id)
            ->whereDate('leave_year_start', $year['start']->toDateString())
            ->orderBy('effective_date')
            ->orderBy('id')
            ->get();

        $granted = $entries
            ->whereIn('entry_type', [
                AllowanceLedgerEntry::TYPE_BASE_GRANT,
                AllowanceLedgerEntry::TYPE_SERVICE_INCREMENT,
                AllowanceLedgerEntry::TYPE_CARRY_OVER,
            ])
            ->sum('minutes');

        $manual = $entries
            ->where('entry_type', AllowanceLedgerEntry::TYPE_MANUAL_ADJUSTMENT)
            ->sum('minutes');

        $used = abs(min(0, $entries
            ->where('entry_type', AllowanceLedgerEntry::TYPE_BOOKING_DEDUCTION)
            ->sum('minutes')));

        $reversed = $entries
            ->where('entry_type', AllowanceLedgerEntry::TYPE_BOOKING_REVERSAL)
            ->sum('minutes');

        $remaining = $entries->sum('minutes');

        return [
            'leave_year' => [
                'start' => $year['start']->toDateString(),
                'end' => $year['end']->toDateString(),
            ],
            'unit' => $user->allowance_unit ?: 'days',
            'minutes_per_day' => self::MINUTES_PER_DAY,
            'granted_minutes' => (int) $granted,
            'manual_adjustment_minutes' => (int) $manual,
            'used_minutes' => (int) $used,
            'reversed_minutes' => (int) $reversed,
            'remaining_minutes' => (int) $remaining,
            'granted_days' => $this->minutesToDays((int) $granted),
            'used_days' => $this->minutesToDays((int) $used),
            'remaining_days' => $this->minutesToDays((int) $remaining),
            'entries' => $entries->map(fn (AllowanceLedgerEntry $entry) => [
                'id' => $entry->id,
                'type' => $entry->entry_type,
                'minutes' => $entry->minutes,
                'days' => $this->minutesToDays($entry->minutes),
                'effective_date' => $entry->effective_date?->toDateString(),
                'note' => $entry->note,
                'metadata' => $entry->metadata,
            ])->values(),
        ];
    }

    private function ensureCarryOver(
        User $user,
        Organisation $organisation,
        array $year,
        array $policy,
    ): void {
        $carryOverEnabled = $user->carry_over_override !== null
            ? (bool) $user->carry_over_override
            : (bool) $policy['carry_over_enabled'];

        if (! $carryOverEnabled) {
            AllowanceLedgerEntry::query()
                ->where('user_id', $user->id)
                ->whereDate('leave_year_start', $year['start']->toDateString())
                ->where('source_key', 'carry-over')
                ->delete();

            return;
        }

        $previousAnchor = $year['start']->subDay();
        $previousYear = $this->leaveYearFor($organisation, $previousAnchor);

        $previousEntries = AllowanceLedgerEntry::query()
            ->where('user_id', $user->id)
            ->whereDate('leave_year_start', $previousYear['start']->toDateString())
            ->get();

        if ($previousEntries->isEmpty()) {
            return;
        }

        $carryMinutes = max(0, (int) $previousEntries->sum('minutes'));
        $maximumCarryDays = $user->carry_over_max_days_override !== null
            ? (int) $user->carry_over_max_days_override
            : $policy['maximum_carry_over_days'];

        if ($maximumCarryDays !== null && $maximumCarryDays !== '') {
            $carryMinutes = min(
                $carryMinutes,
                $this->daysToMinutes((float) $maximumCarryDays),
            );
        }

        if ($carryMinutes <= 0) {
            AllowanceLedgerEntry::query()
                ->where('user_id', $user->id)
                ->whereDate('leave_year_start', $year['start']->toDateString())
                ->where('source_key', 'carry-over')
                ->delete();

            return;
        }

        $this->upsertSystemEntry(
            user: $user,
            year: $year,
            type: AllowanceLedgerEntry::TYPE_CARRY_OVER,
            minutes: $carryMinutes,
            sourceKey: 'carry-over',
            note: 'Unused holiday carried over from previous leave year',
            metadata: [
                'from_leave_year_start' => $previousYear['start']->toDateString(),
                'from_leave_year_end' => $previousYear['end']->toDateString(),
                'carry_over_source' => $user->carry_over_override !== null
                    ? 'person_override'
                    : 'organisation_policy',
                'maximum_carry_over_days' => $maximumCarryDays,
            ],
        );
    }

    private function upsertSystemEntry(
        User $user,
        array $year,
        string $type,
        int $minutes,
        string $sourceKey,
        string $note,
        array $metadata = [],
    ): void {
        $entry = AllowanceLedgerEntry::query()
            ->where('user_id', $user->id)
            ->whereDate('leave_year_start', $year['start']->toDateString())
            ->where('source_key', $sourceKey)
            ->first();

        $values = [
            'leave_year_end' => $year['end']->toDateString(),
            'entry_type' => $type,
            'minutes' => $minutes,
            'effective_date' => $year['start']->toDateString(),
            'note' => $note,
            'metadata' => $metadata,
        ];

        if ($entry) {
            $entry->update($values);
            return;
        }

        AllowanceLedgerEntry::query()->create([
            'user_id' => $user->id,
            'leave_year_start' => $year['start']->toDateString(),
            'source_key' => $sourceKey,
            ...$values,
        ]);
    }

    private function daysToMinutes(float $days): int
    {
        return (int) round($days * self::MINUTES_PER_DAY);
    }

    private function minutesToDays(int $minutes): float
    {
        return round($minutes / self::MINUTES_PER_DAY, 2);
    }
}
