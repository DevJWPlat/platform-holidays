<?php

namespace App\Services;

use App\Models\User;
use App\Models\WorkSchedule;
use App\Models\WorkScheduleAssignment;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

class LeaveDurationService
{
    public function calculate(
        User $user,
        CarbonImmutable $startsOn,
        string $startSession,
        CarbonImmutable $endsOn,
        string $endSession,
    ): array {
        if ($endsOn->lessThan($startsOn)) {
            throw ValidationException::withMessages([
                'ends_on' => ['The ending date must be on or after the starting date.'],
            ]);
        }

        if (
            $startsOn->isSameDay($endsOn)
            && $startSession === 'afternoon'
            && $endSession === 'morning'
        ) {
            throw ValidationException::withMessages([
                'end_session' => ['The ending session must be after the starting session.'],
            ]);
        }

        $minutes = 0;
        $days = [];
        $cursor = $startsOn->startOfDay();
        $end = $endsOn->startOfDay();

        while ($cursor->lessThanOrEqualTo($end)) {
            $schedule = $this->scheduleFor($user, $cursor);

            $sessions = $schedule
                ? $schedule->sessions()
                    ->where('week_number', 1)
                    ->where('weekday', $cursor->dayOfWeekIso)
                    ->orderByRaw("CASE WHEN session = 'morning' THEN 1 WHEN session = 'afternoon' THEN 2 ELSE 3 END")
                    ->get()
                : collect();

            $dayMinutes = 0;

            foreach ($sessions as $session) {
                if ($cursor->isSameDay($startsOn)) {
                    if ($startSession === 'afternoon' && $session->session === 'morning') {
                        continue;
                    }
                }

                if ($cursor->isSameDay($endsOn)) {
                    if ($endSession === 'morning' && $session->session === 'afternoon') {
                        continue;
                    }
                }

                $dayMinutes += (int) $session->duration_minutes;
            }

            if ($dayMinutes > 0) {
                $days[] = [
                    'date' => $cursor->toDateString(),
                    'minutes' => $dayMinutes,
                ];
                $minutes += $dayMinutes;
            }

            $cursor = $cursor->addDay();
        }

        if ($minutes <= 0) {
            throw ValidationException::withMessages([
                'starts_on' => ['The selected period does not contain any working time.'],
            ]);
        }

        return [
            'minutes' => $minutes,
            'days' => round($minutes / AllowanceService::MINUTES_PER_DAY, 2),
            'breakdown' => $days,
        ];
    }

    private function scheduleFor(User $user, CarbonImmutable $date): ?WorkSchedule
    {
        $assignment = WorkScheduleAssignment::query()
            ->where('user_id', $user->id)
            ->whereDate('effective_from', '<=', $date->toDateString())
            ->where(function ($query) use ($date) {
                $query
                    ->whereNull('effective_until')
                    ->orWhereDate('effective_until', '>=', $date->toDateString());
            })
            ->with('workSchedule.sessions')
            ->orderByDesc('effective_from')
            ->first();

        if ($assignment?->workSchedule) {
            return $assignment->workSchedule;
        }

        return WorkSchedule::query()
            ->where('organisation_id', $user->organisation_id)
            ->where('is_default', true)
            ->where('is_active', true)
            ->with('sessions')
            ->first();
    }
}
