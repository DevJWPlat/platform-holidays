<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\LeaveRequestResource;
use App\Models\AllowanceLedgerEntry;
use App\Models\ApproverAssignment;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\User;
use App\Services\AllowanceService;
use App\Services\CompanyClosureService;
use App\Services\BankHolidayService;
use App\Services\LeaveDurationService;
use App\Services\LeaveRequestNotificationService;
use App\Services\StaffingConflictService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class LeaveRequestController extends Controller
{
    public function index(
        Request $request,
        CompanyClosureService $companyClosureService,
    ) {
        $user = $request->user();

        $requests = LeaveRequest::query()
            ->where('user_id', $user->id)
            ->with(['leaveType', 'reviewer'])
            ->latest('starts_on')
            ->latest('id')
            ->get();

        $realItems = LeaveRequestResource::collection($requests)
            ->resolve($request);

        $closureItems = $companyClosureService
            ->virtualRequestsFor($user);

        return response()->json([
            'data' => collect($realItems)
                ->concat($closureItems)
                ->sortByDesc('starts_on')
                ->values(),
        ]);
    }

    public function pending(Request $request)
    {
        $reviewer = $request->user();

        if (! $reviewer->isApprover()) {
            return response()->json(['data' => []]);
        }

        $requests = LeaveRequest::query()
            ->where('status', LeaveRequest::STATUS_PENDING)
            ->whereHas('user', function ($query) use ($reviewer) {
                $query->where('organisation_id', $reviewer->organisation_id);
            })
            ->with(['leaveType', 'user.departments', 'reviewer'])
            ->oldest('created_at')
            ->get()
            ->filter(fn (LeaveRequest $leaveRequest) => $this->canReview($reviewer, $leaveRequest))
            ->values();

        return LeaveRequestResource::collection($requests);
    }

    public function preview(
        Request $request,
        LeaveDurationService $durationService,
        AllowanceService $allowanceService,
        BankHolidayService $bankHolidayService,
        StaffingConflictService $staffingConflictService,
    ): JsonResponse {
        $validated = $this->validatePayload($request);
        $user = $request->user();
        $leaveType = $this->leaveTypeFor($user->organisation_id, (int) $validated['leave_type_id']);

        $startsOn = CarbonImmutable::parse($validated['starts_on']);
        $endsOn = CarbonImmutable::parse($validated['ends_on']);

        $this->assertBookableDates($user, $startsOn, $endsOn);

        $this->enforceFullDayHoliday($leaveType, $validated);

        $duration = $durationService->calculate(
            $user,
            $startsOn,
            $validated['start_session'],
            $endsOn,
            $validated['end_session'],
        );

        $duration = $bankHolidayService->excludeFromDuration(
            $user,
            $duration,
        );

        $allowance = null;

        if ($leaveType->isHoliday()) {
            $allowance = $this->holidayImpact(
                $user,
                $duration['breakdown'],
                $allowanceService,
            );
        }

        $conflicts = $staffingConflictService->check(
            $user,
            $leaveType,
            $duration['breakdown'],
            false,
        );

        return response()->json([
            'data' => [
                'duration' => $duration,
                'leave_type' => [
                    'id' => $leaveType->id,
                    'label' => $leaveType->label,
                    'is_protected_holiday' => $leaveType->is_protected_holiday,
                    'requires_approval' => $leaveType->requires_approval,
                ],
                'allowance' => $allowance,
                'staffing' => [
                    'conflicts' => $conflicts,
                    'has_conflicts' => count($conflicts) > 0,
                    'can_override' => $user->canOverrideStaffingLimits(),
                ],
            ],
        ]);
    }

    public function store(
        Request $request,
        LeaveDurationService $durationService,
        AllowanceService $allowanceService,
        LeaveRequestNotificationService $notificationService,
        BankHolidayService $bankHolidayService,
        StaffingConflictService $staffingConflictService,
    
    ): JsonResponse {
        $validated = $this->validatePayload($request);
        $user = $request->user();
        $leaveType = $this->leaveTypeFor($user->organisation_id, (int) $validated['leave_type_id']);

        $startsOn = CarbonImmutable::parse($validated['starts_on']);
        $endsOn = CarbonImmutable::parse($validated['ends_on']);

        $this->assertBookableDates($user, $startsOn, $endsOn);

        $duration = $durationService->calculate(
            $user,
            $startsOn,
            $validated['start_session'],
            $endsOn,
            $validated['end_session'],
        );

        $duration = $bankHolidayService->excludeFromDuration(
            $user,
            $duration,
        );

        $holidayImpact = null;

        if ($leaveType->isHoliday()) {
            $holidayImpact = $this->holidayImpact(
                $user,
                $duration['breakdown'],
                $allowanceService,
            );

            foreach ($holidayImpact['years'] as $year) {
                if ($year['remaining_after_minutes'] < 0) {
                    throw ValidationException::withMessages([
                        'starts_on' => [
                            sprintf(
                                'This request exceeds your available holiday allowance for the leave year starting %s.',
                                $year['leave_year_start'],
                            ),
                        ],
                    ]);
                }
            }
        }

        $conflicts = $staffingConflictService->check(
            $user,
            $leaveType,
            $duration['breakdown'],
            false,
        );

        $wantsOverride = (bool) ($validated['override_staffing_conflicts'] ?? false);

        if (count($conflicts) > 0) {
            if (! $wantsOverride) {
                throw ValidationException::withMessages([
                    'staffing' => [$conflicts[0]['message']],
                ]);
            }

            if (! $user->canOverrideStaffingLimits()) {
                abort(403, 'You do not have permission to override staffing limits.');
            }

            if (! trim((string) ($validated['override_reason'] ?? ''))) {
                throw ValidationException::withMessages([
                    'override_reason' => ['Add a reason for overriding the staffing rule.'],
                ]);
            }
        }

        $leaveRequest = DB::transaction(function () use (
            $user,
            $leaveType,
            $validated,
            $duration,
            $startsOn,
            $holidayImpact,
            $conflicts,
            $wantsOverride,
        ) {
            $hasOverride = count($conflicts) > 0 && $wantsOverride;

            $leaveRequest = LeaveRequest::query()->create([
                'user_id' => $user->id,
                'leave_type_id' => $leaveType->id,
                'starts_on' => $validated['starts_on'],
                'start_session' => $validated['start_session'],
                'ends_on' => $validated['ends_on'],
                'end_session' => $validated['end_session'],
                'duration_minutes' => $duration['minutes'],
                'status' => $leaveType->requires_approval
                    ? LeaveRequest::STATUS_PENDING
                    : LeaveRequest::STATUS_APPROVED,
                'reason' => $validated['reason'] ?? null,
                'is_manual' => false,
                'staffing_override' => $hasOverride,
                'staffing_override_by' => $hasOverride ? $user->id : null,
                'staffing_override_reason' => $hasOverride
                    ? trim((string) $validated['override_reason'])
                    : null,
                'reviewed_at' => $leaveType->requires_approval ? null : now(),
            ]);

            if ($leaveType->isHoliday() && $holidayImpact) {
                foreach ($holidayImpact['years'] as $year) {
                    AllowanceLedgerEntry::query()->create([
                        'user_id' => $user->id,
                        'leave_year_start' => $year['leave_year_start'],
                        'leave_year_end' => $year['leave_year_end'],
                        'entry_type' => AllowanceLedgerEntry::TYPE_BOOKING_DEDUCTION,
                        'minutes' => -1 * $year['requested_minutes'],
                        'effective_date' => $startsOn->toDateString(),
                        'reference_type' => LeaveRequest::class,
                        'reference_id' => $leaveRequest->id,
                        'source_key' => sprintf(
                            'leave-request-%d-%s',
                            $leaveRequest->id,
                            $year['leave_year_start'],
                        ),
                        'note' => sprintf('%s request', $leaveType->label),
                        'metadata' => [
                            'status_at_creation' => $leaveRequest->status,
                            'staffing_override' => $hasOverride,
                            'staffing_conflicts' => $conflicts,
                        ],
                    ]);
                }
            }

            return $leaveRequest;
        });

        if ($leaveRequest->status === LeaveRequest::STATUS_PENDING) {
            $notificationService->submitted($leaveRequest);
        }



        return response()->json([
            'data' => new LeaveRequestResource(
                $leaveRequest->load(['leaveType', 'reviewer']),
            ),
        ], 201);
    }

    public function approve(
        Request $request,
        LeaveRequest $leaveRequest,
        LeaveRequestNotificationService $notificationService,
    ): JsonResponse
    {
        $reviewer = $request->user();

        $leaveRequest->loadMissing('user.departments');

        abort_unless($this->canReview($reviewer, $leaveRequest), 403);

        if ($leaveRequest->status !== LeaveRequest::STATUS_PENDING) {
            throw ValidationException::withMessages([
                'status' => ['Only pending requests can be approved.'],
            ]);
        }

        $leaveRequest->forceFill([
            'status' => LeaveRequest::STATUS_APPROVED,
            'reviewed_by' => $reviewer->id,
            'reviewed_at' => now(),
            'review_note' => null,
        ])->save();

        $notificationService->approved(
            $leaveRequest->fresh()->load(['user', 'leaveType']),
            $reviewer,
        );

        return response()->json([
            'data' => new LeaveRequestResource(
                $leaveRequest->fresh()->load(['leaveType', 'user.departments', 'reviewer']),
            ),
        ]);
    }

    public function reject(
        Request $request,
        LeaveRequest $leaveRequest,
        LeaveRequestNotificationService $notificationService,
    ): JsonResponse
    {
        $reviewer = $request->user();

        $leaveRequest->loadMissing('user.departments', 'leaveType');

        abort_unless($this->canReview($reviewer, $leaveRequest), 403);

        if ($leaveRequest->status !== LeaveRequest::STATUS_PENDING) {
            throw ValidationException::withMessages([
                'status' => ['Only pending requests can be rejected.'],
            ]);
        }

        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:2000'],
        ]);

        DB::transaction(function () use ($leaveRequest, $reviewer, $validated) {
            $leaveRequest->forceFill([
                'status' => LeaveRequest::STATUS_REJECTED,
                'reviewed_by' => $reviewer->id,
                'reviewed_at' => now(),
                'review_note' => $validated['reason'],
            ])->save();

            $this->reverseAllowance($leaveRequest, 'Request rejected');
        });

        $notificationService->rejected(
            $leaveRequest->fresh()->load(['user', 'leaveType']),
            $reviewer,
        );

        return response()->json([
            'data' => new LeaveRequestResource(
                $leaveRequest->fresh()->load(['leaveType', 'user.departments', 'reviewer']),
            ),
        ]);
    }

    public function cancel(Request $request, LeaveRequest $leaveRequest): JsonResponse
    {
        $user = $request->user();

        abort_unless($leaveRequest->user_id === $user->id, 403);

        if (! in_array($leaveRequest->status, [
            LeaveRequest::STATUS_PENDING,
            LeaveRequest::STATUS_APPROVED,
        ], true)) {
            throw ValidationException::withMessages([
                'status' => ['This request can no longer be cancelled.'],
            ]);
        }

        if ($leaveRequest->starts_on->isBefore(today())) {
            throw ValidationException::withMessages([
                'status' => ['Past leave cannot be cancelled from the app.'],
            ]);
        }

        DB::transaction(function () use ($leaveRequest) {
            $leaveRequest->forceFill([
                'status' => LeaveRequest::STATUS_CANCELLED,
                'cancelled_at' => now(),
            ])->save();

            $this->reverseAllowance($leaveRequest, 'Request cancelled');
        });

        return response()->json([
            'data' => new LeaveRequestResource(
                $leaveRequest->fresh()->load(['leaveType', 'reviewer']),
            ),
        ]);
    }

    private function assertBookableDates(
        User $user,
        CarbonImmutable $startsOn,
        CarbonImmutable $endsOn,
    ): void {
        $timezone = $user->organisation?->timezone ?: 'Europe/London';
        $today = CarbonImmutable::today($timezone);

        if ($startsOn->startOfDay()->lessThan($today)) {
            throw ValidationException::withMessages([
                'starts_on' => [
                    'Time off can only be requested for today or a future date. '
                    . 'Historic leave can be added by management.',
                ],
            ]);
        }

        $closure = app(CompanyClosureService::class)->overlaps(
            $user,
            $startsOn,
            $endsOn,
        );

        if ($closure) {
            throw ValidationException::withMessages([
                'starts_on' => [
                    sprintf(
                        'This request overlaps the company Festive Break (%s to %s).',
                        CarbonImmutable::parse($closure['starts_on'])->format('j M Y'),
                        CarbonImmutable::parse($closure['ends_on'])->format('j M Y'),
                    ),
                ],
            ]);
        }

        $overlap = LeaveRequest::query()
            ->where('user_id', $user->id)
            ->whereIn('status', [
                LeaveRequest::STATUS_PENDING,
                LeaveRequest::STATUS_APPROVED,
            ])
            ->whereDate('starts_on', '<=', $endsOn->toDateString())
            ->whereDate('ends_on', '>=', $startsOn->toDateString())
            ->exists();

        if ($overlap) {
            throw ValidationException::withMessages([
                'starts_on' => [
                    'You already have time off booked or awaiting approval '
                    . 'on at least one of these dates.',
                ],
            ]);
        }
    }

    private function validatePayload(Request $request): array
    {
        return $request->validate([
            'leave_type_id' => ['required', 'integer'],
            'starts_on' => ['required', 'date'],
            'start_session' => ['required', Rule::in(['morning', 'afternoon'])],
            'ends_on' => ['required', 'date', 'after_or_equal:starts_on'],
            'end_session' => ['required', Rule::in(['morning', 'afternoon'])],
            'reason' => ['nullable', 'string', 'max:2000'],
            'override_staffing_conflicts' => ['sometimes', 'boolean'],
            'override_reason' => ['nullable', 'string', 'max:2000'],
        ]);
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
                    'This leave type must be booked as full days. Half days are not available.',
                ],
            ]);
        }
    }

    private function leaveTypeFor(int $organisationId, int $leaveTypeId): LeaveType
    {
        return LeaveType::query()
            ->where('organisation_id', $organisationId)
            ->where('is_active', true)
            ->findOrFail($leaveTypeId);
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
                round($summary['remaining_minutes'] / AllowanceService::MINUTES_PER_DAY, 2);
            $year['remaining_after_days'] =
                round($year['remaining_after_minutes'] / AllowanceService::MINUTES_PER_DAY, 2);
            $year['requested_days'] =
                round($year['requested_minutes'] / AllowanceService::MINUTES_PER_DAY, 2);

            $years[] = $year;
        }

        return [
            'requested_minutes' => array_sum(array_column($years, 'requested_minutes')),
            'requested_days' => round(
                array_sum(array_column($years, 'requested_minutes')) / AllowanceService::MINUTES_PER_DAY,
                2,
            ),
            'years' => $years,
        ];
    }

    private function canReview(User $reviewer, LeaveRequest $leaveRequest): bool
    {
        if ($reviewer->organisation_id !== $leaveRequest->user->organisation_id) {
            return false;
        }

        if ($reviewer->isAdministrator()) {
            return true;
        }

        $today = today()->toDateString();

        $activeRules = ApproverAssignment::query()
            ->where('organisation_id', $reviewer->organisation_id)
            ->where(function ($query) use ($today) {
                $query
                    ->whereNull('effective_from')
                    ->orWhereDate('effective_from', '<=', $today);
            })
            ->where(function ($query) use ($today) {
                $query
                    ->whereNull('effective_until')
                    ->orWhereDate('effective_until', '>=', $today);
            });

        /*
         * If this employee has one or more person-specific approver rules,
         * those rules override department-level approvers.
         */
        $personRules = (clone $activeRules)
            ->where('employee_id', $leaveRequest->user_id)
            ->orderBy('priority')
            ->get();

        if ($personRules->isNotEmpty()) {
            return $personRules->contains(
                fn (ApproverAssignment $assignment) =>
                    (int) $assignment->approver_id === (int) $reviewer->id,
            );
        }

        $departmentIds = $leaveRequest->user->departments->pluck('id');

        $departmentRules = (clone $activeRules)
            ->whereNull('employee_id')
            ->whereIn('department_id', $departmentIds)
            ->orderBy('priority')
            ->get();

        if ($departmentRules->isNotEmpty()) {
            return $departmentRules->contains(
                fn (ApproverAssignment $assignment) =>
                    (int) $assignment->approver_id === (int) $reviewer->id,
            );
        }

        /*
         * Fallback for departments that do not yet have explicit approval
         * rules: a configured department manager can still review.
         */
        if ($reviewer->isDepartmentManager()) {
            $managedDepartmentIds = $reviewer->departments()
                ->wherePivot('is_manager', true)
                ->pluck('departments.id');

            return $departmentIds
                ->intersect($managedDepartmentIds)
                ->isNotEmpty();
        }

        return false;
    }

    private function reverseAllowance(LeaveRequest $leaveRequest, string $reason): void
    {
        $deductions = AllowanceLedgerEntry::query()
            ->where('user_id', $leaveRequest->user_id)
            ->where('reference_type', LeaveRequest::class)
            ->where('reference_id', $leaveRequest->id)
            ->where('entry_type', AllowanceLedgerEntry::TYPE_BOOKING_DEDUCTION)
            ->get();

        foreach ($deductions as $deduction) {
            AllowanceLedgerEntry::query()->firstOrCreate(
                [
                    'user_id' => $leaveRequest->user_id,
                    'leave_year_start' => $deduction->leave_year_start->toDateString(),
                    'source_key' => sprintf(
                        'leave-request-reversal-%d-%s',
                        $leaveRequest->id,
                        $deduction->leave_year_start->toDateString(),
                    ),
                ],
                [
                    'leave_year_end' => $deduction->leave_year_end->toDateString(),
                    'entry_type' => AllowanceLedgerEntry::TYPE_BOOKING_REVERSAL,
                    'minutes' => abs((int) $deduction->minutes),
                    'effective_date' => now()->toDateString(),
                    'reference_type' => LeaveRequest::class,
                    'reference_id' => $leaveRequest->id,
                    'note' => $reason,
                    'metadata' => [
                        'reverses_ledger_entry_id' => $deduction->id,
                    ],
                ],
            );
        }
    }
}
