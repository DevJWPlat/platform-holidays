<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AllowanceLedgerEntry;
use App\Models\GroupBooking;
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
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class GroupBookingController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $viewer = $request->user();
        abort_unless($viewer->isAdministrator(), 403);

        $items = GroupBooking::query()
            ->where('organisation_id', $viewer->organisation_id)
            ->with(['leaveType', 'department', 'creator', 'leaveRequests.user'])
            ->latest('starts_on')->latest('id')->get()
            ->map(fn (GroupBooking $booking) => $this->resource($booking))->values();

        return response()->json(['data' => $items]);
    }

    public function store(Request $request, LeaveDurationService $durationService, BankHolidayService $bankHolidayService, AllowanceService $allowanceService): JsonResponse
    {
        $viewer = $request->user();
        abort_unless($viewer->isAdministrator(), 403);

        $validated = $request->validate([
            'leave_type_id' => ['required', 'integer'],
            'scope_type' => ['required', Rule::in(['all', 'department', 'people'])],
            'department_id' => ['nullable', 'integer'],
            'user_ids' => ['array'],
            'user_ids.*' => ['integer'],
            'starts_on' => ['required', 'date'],
            'ends_on' => ['required', 'date', 'after_or_equal:starts_on'],
            'reason' => ['nullable', 'string', 'max:1000'],
        ]);

        $leaveType = LeaveType::query()
            ->where('organisation_id', $viewer->organisation_id)
            ->where('is_active', true)
            ->findOrFail($validated['leave_type_id']);

        $peopleQuery = User::query()
            ->where('organisation_id', $viewer->organisation_id)
            ->where('is_archived', false);

        if ($validated['scope_type'] === 'department') {
            if (! ($validated['department_id'] ?? null)) {
                throw ValidationException::withMessages(['department_id' => ['Choose a department.']]);
            }
            $peopleQuery->whereHas('departments', function ($query) use ($validated) {
                $query->where('departments.id', (int) $validated['department_id']);
            });
        }

        if ($validated['scope_type'] === 'people') {
            $ids = collect($validated['user_ids'] ?? [])->map(fn ($id) => (int) $id)->filter()->unique()->values();
            if ($ids->isEmpty()) {
                throw ValidationException::withMessages(['user_ids' => ['Choose at least one person.']]);
            }
            $peopleQuery->whereIn('id', $ids);
        }

        $people = $peopleQuery->with('departments')->orderBy('name')->get();
        if ($people->isEmpty()) {
            throw ValidationException::withMessages(['people' => ['No active people matched this group booking.']]);
        }

        $startsOn = CarbonImmutable::parse($validated['starts_on'])->startOfDay();
        $endsOn = CarbonImmutable::parse($validated['ends_on'])->startOfDay();
        $conflicts = [];
        $prepared = [];

        foreach ($people as $person) {
            $existing = LeaveRequest::query()
                ->where('user_id', $person->id)
                ->whereIn('status', [LeaveRequest::STATUS_PENDING, LeaveRequest::STATUS_APPROVED])
                ->whereDate('starts_on', '<=', $endsOn->toDateString())
                ->whereDate('ends_on', '>=', $startsOn->toDateString())
                ->with('leaveType')->first();

            if ($existing) {
                $conflicts[] = sprintf('%s already has %s booked (%s to %s).', $person->name, $existing->leaveType?->label ?: 'leave', $existing->starts_on?->toDateString(), $existing->ends_on?->toDateString());
                continue;
            }

            $duration = $durationService->calculate($person, $startsOn, 'morning', $endsOn, 'afternoon');
            $duration = $bankHolidayService->excludeFromDuration($person, $duration);

            if ((int) $duration['minutes'] <= 0) {
                $conflicts[] = $person->name . ' has no working time in the selected dates.';
                continue;
            }

            if ($leaveType->isHoliday()) {
                $summary = $allowanceService->ensureYear($person, $startsOn);
                if ((int) $summary['remaining_minutes'] < (int) $duration['minutes']) {
                    $conflicts[] = $person->name . ' does not have enough holiday allowance.';
                    continue;
                }
                $year = $allowanceService->leaveYearFor($person->organisation()->firstOrFail(), $startsOn);
                if ($endsOn->greaterThan($year['end'])) {
                    $conflicts[] = $person->name . ': group Holiday bookings cannot cross a leave-year boundary.';
                    continue;
                }
            }

            $prepared[] = ['person' => $person, 'duration' => $duration];
        }

        if ($conflicts) {
            throw ValidationException::withMessages(['people' => $conflicts]);
        }

        $booking = DB::transaction(function () use ($viewer, $validated, $leaveType, $prepared, $startsOn, $allowanceService) {
            $group = GroupBooking::query()->create([
                'organisation_id' => $viewer->organisation_id,
                'leave_type_id' => $leaveType->id,
                'created_by' => $viewer->id,
                'scope_type' => $validated['scope_type'],
                'department_id' => $validated['scope_type'] === 'department' ? (int) $validated['department_id'] : null,
                'starts_on' => $validated['starts_on'],
                'ends_on' => $validated['ends_on'],
                'start_session' => 'morning',
                'end_session' => 'afternoon',
                'reason' => trim((string) ($validated['reason'] ?? '')) ?: null,
            ]);

            foreach ($prepared as $item) {
                $person = $item['person'];
                $duration = $item['duration'];
                $attributes = [
                    'user_id' => $person->id,
                    'leave_type_id' => $leaveType->id,
                    'group_booking_id' => $group->id,
                    'starts_on' => $validated['starts_on'],
                    'start_session' => 'morning',
                    'ends_on' => $validated['ends_on'],
                    'end_session' => 'afternoon',
                    'duration_minutes' => (int) $duration['minutes'],
                    'status' => LeaveRequest::STATUS_APPROVED,
                    'reason' => trim((string) ($validated['reason'] ?? '')) ?: null,
                    'reviewed_by' => $viewer->id,
                    'reviewed_at' => now(),
                ];
                if (Schema::hasColumn('leave_requests', 'is_manual')) $attributes['is_manual'] = true;

                $leaveRequest = new LeaveRequest();
                $leaveRequest->forceFill($attributes)->save();

                if ($leaveType->isHoliday()) {
                    $year = $allowanceService->leaveYearFor($person->organisation()->firstOrFail(), $startsOn);
                    AllowanceLedgerEntry::query()->create([
                        'user_id' => $person->id,
                        'leave_year_start' => $year['start']->toDateString(),
                        'leave_year_end' => $year['end']->toDateString(),
                        'entry_type' => AllowanceLedgerEntry::TYPE_BOOKING_DEDUCTION,
                        'minutes' => -1 * abs((int) $duration['minutes']),
                        'effective_date' => $startsOn->toDateString(),
                        'reference_type' => LeaveRequest::class,
                        'reference_id' => $leaveRequest->id,
                        'source_key' => sprintf('group-booking-%d-leave-%d', $group->id, $leaveRequest->id),
                        'note' => $leaveType->label . ' group booking',
                        'metadata' => ['group_booking_id' => $group->id, 'created_by' => $viewer->id],
                    ]);
                }
            }
            return $group;
        });

        $booking->load(['leaveType', 'department', 'creator', 'leaveRequests.user']);
        return response()->json(['data' => $this->resource($booking)], 201);
    }

    public function destroy(Request $request, GroupBooking $groupBooking): JsonResponse
    {
        $viewer = $request->user();
        abort_unless($viewer->isAdministrator(), 403);
        abort_unless((int) $groupBooking->organisation_id === (int) $viewer->organisation_id, 404);

        $groupBooking->load(['leaveType', 'leaveRequests']);

        DB::transaction(function () use ($viewer, $groupBooking) {
            foreach ($groupBooking->leaveRequests as $leaveRequest) {
                if ($leaveRequest->status === LeaveRequest::STATUS_CANCELLED) continue;

                if ($groupBooking->leaveType?->isHoliday()) {
                    $deduction = AllowanceLedgerEntry::query()
                        ->where('reference_type', LeaveRequest::class)
                        ->where('reference_id', $leaveRequest->id)
                        ->where('entry_type', AllowanceLedgerEntry::TYPE_BOOKING_DEDUCTION)
                        ->first();

                    if ($deduction && $deduction->minutes < 0) {
                        AllowanceLedgerEntry::query()->updateOrCreate(
                            [
                                'user_id' => $leaveRequest->user_id,
                                'leave_year_start' => $deduction->leave_year_start,
                                'source_key' => 'group-booking-reversal-' . $leaveRequest->id,
                            ],
                            [
                                'leave_year_end' => $deduction->leave_year_end,
                                'entry_type' => AllowanceLedgerEntry::TYPE_BOOKING_REVERSAL,
                                'minutes' => abs((int) $deduction->minutes),
                                'effective_date' => now()->toDateString(),
                                'reference_type' => LeaveRequest::class,
                                'reference_id' => $leaveRequest->id,
                                'note' => 'Group booking removed',
                                'metadata' => ['group_booking_id' => $groupBooking->id, 'removed_by' => $viewer->id],
                            ],
                        );
                    }
                }

                $leaveRequest->forceFill(['status' => LeaveRequest::STATUS_CANCELLED, 'cancelled_at' => now()])->save();
            }
            $groupBooking->delete();
        });

        return response()->json(['message' => 'Group booking removed from everyone.']);
    }

    private function resource(GroupBooking $booking): array
    {
        $active = $booking->leaveRequests->where('status', '!=', LeaveRequest::STATUS_CANCELLED)->values();
        return [
            'id' => $booking->id,
            'scope_type' => $booking->scope_type,
            'department' => $booking->department ? ['id' => $booking->department->id, 'name' => $booking->department->name] : null,
            'leave_type' => $booking->leaveType ? ['id' => $booking->leaveType->id, 'label' => $booking->leaveType->label, 'colour' => $booking->leaveType->colour] : null,
            'starts_on' => $booking->starts_on?->toDateString(),
            'ends_on' => $booking->ends_on?->toDateString(),
            'reason' => $booking->reason,
            'people_count' => $active->count(),
            'people' => $active->map(fn (LeaveRequest $item) => ['id' => $item->user?->id, 'name' => $item->user?->name])->filter(fn ($item) => $item['id'])->values(),
            'created_by' => $booking->creator?->name,
        ];
    }
}
