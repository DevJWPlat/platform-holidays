<?php

namespace App\Services;

use App\Models\AllowanceLedgerEntry;
use App\Models\GroupBooking;
use App\Models\LeaveRequest;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Schema;

class WholeCompanyGroupBookingService
{
    public function __construct(
        private readonly LeaveDurationService $durationService,
        private readonly BankHolidayService $bankHolidayService,
        private readonly AllowanceService $allowanceService,
    ) {}

    public function addPersonToCurrentAndFutureBookings(User $person, User $actor): int
    {
        $timezone = $person->organisation?->timezone ?: 'Europe/London';
        $today = CarbonImmutable::now($timezone)->startOfDay();

        $bookings = GroupBooking::query()
            ->where('organisation_id', $person->organisation_id)
            ->where('scope_type', 'all')
            ->whereDate('ends_on', '>=', $today->toDateString())
            ->with('leaveType')
            ->orderBy('starts_on')
            ->get();

        $created = 0;

        foreach ($bookings as $booking) {
            if (! $booking->leaveType) {
                continue;
            }

            // A new starter should not receive a company booking that happens
            // entirely before their employment begins.
            if (
                $person->employment_start_date
                && $booking->ends_on->isBefore($person->employment_start_date)
            ) {
                continue;
            }

            $alreadyLinked = LeaveRequest::query()
                ->where('group_booking_id', $booking->id)
                ->where('user_id', $person->id)
                ->where('status', '!=', LeaveRequest::STATUS_CANCELLED)
                ->exists();

            if ($alreadyLinked) {
                continue;
            }

            $startsOn = CarbonImmutable::parse($booking->starts_on)->startOfDay();
            $endsOn = CarbonImmutable::parse($booking->ends_on)->startOfDay();

            $existingConflict = LeaveRequest::query()
                ->where('user_id', $person->id)
                ->whereIn('status', [
                    LeaveRequest::STATUS_PENDING,
                    LeaveRequest::STATUS_APPROVED,
                ])
                ->whereDate('starts_on', '<=', $endsOn->toDateString())
                ->whereDate('ends_on', '>=', $startsOn->toDateString())
                ->exists();

            if ($existingConflict) {
                continue;
            }

            $duration = $this->durationService->calculate(
                $person,
                $startsOn,
                'morning',
                $endsOn,
                'afternoon',
            );

            $duration = $this->bankHolidayService->excludeFromDuration(
                $person,
                $duration,
            );

            if ((int) ($duration['minutes'] ?? 0) <= 0) {
                continue;
            }

            if ($booking->leaveType->isHoliday()) {
                $year = $this->allowanceService->leaveYearFor(
                    $person->organisation()->firstOrFail(),
                    $startsOn,
                );

                if ($endsOn->greaterThan($year['end'])) {
                    continue;
                }

                $summary = $this->allowanceService->ensureYear($person, $startsOn);

                if (
                    (int) ($summary['remaining_minutes'] ?? 0)
                    < (int) $duration['minutes']
                ) {
                    continue;
                }
            }

            $attributes = [
                'user_id' => $person->id,
                'leave_type_id' => $booking->leave_type_id,
                'group_booking_id' => $booking->id,
                'starts_on' => $booking->starts_on->toDateString(),
                'start_session' => 'morning',
                'ends_on' => $booking->ends_on->toDateString(),
                'end_session' => 'afternoon',
                'duration_minutes' => (int) $duration['minutes'],
                'status' => LeaveRequest::STATUS_APPROVED,
                'reason' => $booking->reason,
                'reviewed_by' => $actor->id,
                'reviewed_at' => now(),
            ];

            if (Schema::hasColumn('leave_requests', 'is_manual')) {
                $attributes['is_manual'] = true;
            }

            $leaveRequest = new LeaveRequest();
            $leaveRequest->forceFill($attributes)->save();

            if ($booking->leaveType->isHoliday()) {
                $year = $this->allowanceService->leaveYearFor(
                    $person->organisation()->firstOrFail(),
                    $startsOn,
                );

                AllowanceLedgerEntry::query()->create([
                    'user_id' => $person->id,
                    'leave_year_start' => $year['start']->toDateString(),
                    'leave_year_end' => $year['end']->toDateString(),
                    'entry_type' => AllowanceLedgerEntry::TYPE_BOOKING_DEDUCTION,
                    'minutes' => -1 * abs((int) $duration['minutes']),
                    'effective_date' => $startsOn->toDateString(),
                    'reference_type' => LeaveRequest::class,
                    'reference_id' => $leaveRequest->id,
                    'source_key' => sprintf(
                        'group-booking-%d-leave-%d',
                        $booking->id,
                        $leaveRequest->id,
                    ),
                    'note' => $booking->leaveType->label . ' group booking',
                    'metadata' => [
                        'group_booking_id' => $booking->id,
                        'created_by' => $actor->id,
                        'auto_added_new_person' => true,
                    ],
                ]);
            }

            $created++;
        }

        return $created;
    }
}
