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
            'maximum_allowance_days' => null,
            'carry_over_enabled' => true,
            'maximum_carry_over_days' => null,
        ], $value);
    }

    public function ensureYear(User $user, CarbonImmutable $anchor): array
    {
        $organisation = $user->organisation()->firstOrFail();
        $year = $this->leaveYearFor($organisation, $anchor);
        $policy = $this->policy($organisation);

        $baseAllowanceDays = $user->holiday_allowance_override_days !== null
            ? (float) $user->holiday_allowance_override_days
            : (float) $policy['base_allowance_days'];

        $baseMinutes = $this->daysToMinutes($baseAllowanceDays);

        $serviceMinutes = 0;
        $completedServiceYears = 0;

        if (
            (bool) $policy['service_increment_enabled']
            && $user->employment_start_date
        ) {
            $serviceDate = CarbonImmutable::parse(
                $user->employment_start_date->toDateString(),
                $organisation->timezone ?: 'Europe/London',
            );

            if ($year['start']->greaterThanOrEqualTo($serviceDate)) {
                $completedServiceYears = max(
                    0,
                    $serviceDate->diffInYears($year['start']),
                );
            }

            $threshold = max(0, (int) $policy['service_increment_after_years']);

            if ($completedServiceYears >= $threshold) {
                $serviceMinutes = $this->daysToMinutes(
                    (float) $policy['service_increment_days'],
                );
            }
        }

        $maxDays = $policy['maximum_allowance_days'];

        if ($maxDays !== null && $maxDays !== '') {
            $maxMinutes = $this->daysToMinutes((float) $maxDays);

            if (($baseMinutes + $serviceMinutes) > $maxMinutes) {
                $serviceMinutes = max(0, $maxMinutes - $baseMinutes);
                $baseMinutes = min($baseMinutes, $maxMinutes);
            }
        }

        $this->upsertSystemEntry(
            user: $user,
            year: $year,
            type: AllowanceLedgerEntry::TYPE_BASE_GRANT,
            minutes: $baseMinutes,
            sourceKey: 'base-grant',
            note: 'Annual holiday allowance',
            metadata: [
                'base_allowance_days' => $baseAllowanceDays,
                'organisation_default_days' => (float) $policy['base_allowance_days'],
                'uses_person_override' => $user->holiday_allowance_override_days !== null,
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
                    'completed_service_years' => $completedServiceYears,
                    'service_increment_days' => (float) $policy['service_increment_days'],
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
