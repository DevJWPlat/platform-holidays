<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\LeaveRequestResource;
use App\Models\AllowanceLedgerEntry;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\User;
use App\Services\AllowanceService;
use App\Services\BankHolidayService;
use App\Services\LeaveDurationService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ManualLeaveController extends Controller
{
    public function preview(
        Request $request,
        LeaveDurationService $durationService,
        AllowanceService $allowanceService,
        BankHolidayService $bankHolidayService,
    ): JsonResponse {
        $admin = $request->user();
        abort_unless($admin->isAdministrator(), 403);

        $validated = $this->validatePayload($request);

        $person = User::query()
            ->where('organisation_id', $admin->organisation_id)
            ->where('is_archived', false)
            ->findOrFail((int) $validated['user_id']);

        $leaveType = LeaveType::query()
            ->where('organisation_id', $admin->organisation_id)
            ->where('is_active', true)
            ->findOrFail((int) $validated['leave_type_id']);

        $this->enforceFullDayHoliday($leaveType, $validated);

        $duration = $durationService->calculate(
            $person,
            CarbonImmutable::parse($validated['starts_on']),
            $validated['start_session'],
            CarbonImmutable::parse($validated['ends_on']),
            $validated['end_session'],
        );

        $duration = $bankHolidayService->excludeFromDuration(
            $person,
            $duration,
        );

        $allowance = $leaveType->isHoliday()
            ? $this->holidayImpact($person, $duration['breakdown'], $allowanceService)
            : null;

        return response()->json([
            'data' => [
                'person' => [
                    'id' => $person->id,
                    'name' => $person->name,
                ],
                'leave_type' => [
                    'id' => $leaveType->id,
                    'label' => $leaveType->label,
                    'key' => $leaveType->key,
                    'is_protected_holiday' => $leaveType->is_protected_holiday,
                ],
                'duration' => $duration,
                'allowance' => $allowance,
                'staffing' => [
                    'bypassed' => true,
                    'message' => 'Manual leave bypasses staffing-limit checks.',
                ],
                'approval' => [
                    'required' => false,
                    'message' => 'Manual leave is added as approved immediately.',
                ],
            ],
        ]);
    }

    public function store(
        Request $request,
        LeaveDurationService $durationService,
        AllowanceService $allowanceService,
        BankHolidayService $bankHolidayService,
    ): JsonResponse {
        $admin = $request->user();
        abort_unless($admin->isAdministrator(), 403);

        $validated = $this->validatePayload($request);

        $person = User::query()
            ->where('organisation_id', $admin->organisation_id)
            ->where('is_archived', false)
            ->findOrFail((int) $validated['user_id']);

        $leaveType = LeaveType::query()
            ->where('organisation_id', $admin->organisation_id)
            ->where('is_active', true)
            ->findOrFail((int) $validated['leave_type_id']);

        $startsOn = CarbonImmutable::parse($validated['starts_on']);
        $endsOn = CarbonImmutable::parse($validated['ends_on']);

        $duration = $durationService->calculate(
            $person,
            $startsOn,
            $validated['start_session'],
            $endsOn,
            $validated['end_session'],
        );

        $duration = $bankHolidayService->excludeFromDuration(
            $person,
            $duration,
        );

        $holidayImpact = null;

        if ($leaveType->isHoliday()) {
            $holidayImpact = $this->holidayImpact(
                $person,
                $duration['breakdown'],
                $allowanceService,
            );

            foreach ($holidayImpact['years'] as $year) {
                if ($year['remaining_after_minutes'] < 0) {
                    throw ValidationException::withMessages([
                        'starts_on' => [
                            sprintf(
                                '%s does not have enough holiday allowance for the leave year starting %s.',
                                $person->name,
                                $year['leave_year_start'],
                            ),
                        ],
                    ]);
                }
            }
        }

        $leaveRequest = DB::transaction(function () use (
            $admin,
            $person,
            $leaveType,
            $validated,
            $duration,
            $startsOn,
            $holidayImpact,
        ) {
            $leaveRequest = LeaveRequest::query()->create([
                'user_id' => $person->id,
                'created_by' => $admin->id,
                'leave_type_id' => $leaveType->id,
                'starts_on' => $validated['starts_on'],
                'start_session' => $validated['start_session'],
                'ends_on' => $validated['ends_on'],
                'end_session' => $validated['end_session'],
                'duration_minutes' => $duration['minutes'],
                'status' => LeaveRequest::STATUS_APPROVED,
                'reason' => $validated['reason'] ?? null,
                'is_manual' => true,
                'staffing_override' => false,
                'reviewed_by' => $admin->id,
                'reviewed_at' => now(),
                'review_note' => 'Manually added by ' . $admin->name,
            ]);

            if ($leaveType->isHoliday() && $holidayImpact) {
                foreach ($holidayImpact['years'] as $year) {
                    AllowanceLedgerEntry::query()->create([
                        'user_id' => $person->id,
                        'leave_year_start' => $year['leave_year_start'],
                        'leave_year_end' => $year['leave_year_end'],
                        'entry_type' => AllowanceLedgerEntry::TYPE_BOOKING_DEDUCTION,
                        'minutes' => -1 * $year['requested_minutes'],
                        'effective_date' => $startsOn->toDateString(),
                        'reference_type' => LeaveRequest::class,
                        'reference_id' => $leaveRequest->id,
                        'source_key' => sprintf(
                            'manual-leave-request-%d-%s',
                            $leaveRequest->id,
                            $year['leave_year_start'],
                        ),
                        'note' => sprintf(
                            'Manual %s entry added by %s',
                            $leaveType->label,
                            $admin->name,
                        ),
                        'metadata' => [
                            'is_manual' => true,
                            'created_by' => $admin->id,
                        ],
                    ]);
                }
            }

            return $leaveRequest;
        });

        return response()->json([
            'data' => new LeaveRequestResource(
                $leaveRequest->load([
                    'leaveType',
                    'reviewer',
                    'creator',
                ]),
            ),
        ], 201);
    }

    private function enforceFullDayHoliday(LeaveType $leaveType, array $validated): void
    {
        if ($leaveType->allow_half_days) {
            return;
        }

        if (
            $validated['start_session'] !== 'morning'
            || $validated['end_session'] !== 'afternoon'
        ) {
            throw ValidationException::withMessages([
                'starts_on' => [
                    'This leave type must be added as full days. Half days are not available.',
                ],
            ]);
        }
    }

    private function validatePayload(Request $request): array
    {
        return $request->validate([
            'user_id' => ['required', 'integer'],
            'leave_type_id' => ['required', 'integer'],
            'starts_on' => ['required', 'date'],
            'start_session' => ['required', Rule::in(['morning', 'afternoon'])],
            'ends_on' => ['required', 'date', 'after_or_equal:starts_on'],
            'end_session' => ['required', Rule::in(['morning', 'afternoon'])],
            'reason' => ['nullable', 'string', 'max:2000'],
        ]);
    }

    private function holidayImpact(
        User $user,
        array $breakdown,
        AllowanceService $allowanceService,
    ): array {
        $organisation = $user->organisation()->firstOrFail();
        $grouped = [];

        foreach ($breakdown as $day) {
            $date = CarbonImmutable::parse($day['date']);
            $year = $allowanceService->leaveYearFor($organisation, $date);
            $key = $year['start']->toDateString();

            if (! isset($grouped[$key])) {
                $grouped[$key] = [
                    'leave_year_start' => $key,
                    'leave_year_end' => $year['end']->toDateString(),
                    'requested_minutes' => 0,
                ];
            }

            $grouped[$key]['requested_minutes'] += (int) $day['minutes'];
        }

        $years = [];

        foreach ($grouped as $year) {
            $summary = $allowanceService->ensureYear(
                $user,
                CarbonImmutable::parse($year['leave_year_start']),
            );

            $year['remaining_before_minutes'] = $summary['remaining_minutes'];
            $year['remaining_after_minutes'] =
                $summary['remaining_minutes'] - $year['requested_minutes'];

            $year['remaining_before_days'] =
                round(
                    $summary['remaining_minutes'] / AllowanceService::MINUTES_PER_DAY,
                    2,
                );

            $year['remaining_after_days'] =
                round(
                    $year['remaining_after_minutes'] / AllowanceService::MINUTES_PER_DAY,
                    2,
                );

            $year['requested_days'] =
                round(
                    $year['requested_minutes'] / AllowanceService::MINUTES_PER_DAY,
                    2,
                );

            $years[] = $year;
        }

        return [
            'requested_minutes' =>
                array_sum(array_column($years, 'requested_minutes')),
            'requested_days' => round(
                array_sum(array_column($years, 'requested_minutes'))
                / AllowanceService::MINUTES_PER_DAY,
                2,
            ),
            'years' => $years,
        ];
    }
}
