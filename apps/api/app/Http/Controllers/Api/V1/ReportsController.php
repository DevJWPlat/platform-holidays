<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\LeaveRequest;
use App\Models\User;
use App\Services\AllowanceService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;

class ReportsController extends Controller
{
    public function index(Request $request, AllowanceService $allowanceService)
    {
        $viewer = $request->user();

        abort_unless(
            $viewer->isDepartmentManager() || $viewer->isAdministrator(),
            403,
        );

        $timezone = $viewer->organisation?->timezone ?: 'Europe/London';

        $from = CarbonImmutable::parse(
            $request->query('from') ?: now($timezone)->startOfYear()->toDateString(),
            $timezone,
        )->startOfDay();

        $to = CarbonImmutable::parse(
            $request->query('to') ?: now($timezone)->endOfYear()->toDateString(),
            $timezone,
        )->endOfDay();

        $visibleUsers = $this->visibleUsers($viewer);

        if ($departmentId = $request->integer('department_id')) {
            $visibleUsers->whereHas('departments', function ($query) use ($departmentId) {
                $query->where('departments.id', $departmentId);
            });
        }

        if ($personId = $request->integer('person_id')) {
            $visibleUsers->whereKey($personId);
        }

        $people = $visibleUsers->with('departments')->orderBy('name')->get();
        $userIds = $people->pluck('id');

        $leaveTypeId = $request->integer('leave_type_id');

        $requests = LeaveRequest::query()
            ->whereIn('user_id', $userIds)
            ->whereDate('starts_on', '<=', $to->toDateString())
            ->whereDate('ends_on', '>=', $from->toDateString())
            ->with(['user.departments', 'leaveType', 'reviewer', 'creator'])
            ->when(
                $leaveTypeId,
                fn ($query) => $query->where('leave_type_id', $leaveTypeId),
            )
            ->get();

        $approved = $requests->where('status', LeaveRequest::STATUS_APPROVED);
        $pending = $requests->where('status', LeaveRequest::STATUS_PENDING);

        $holiday = $approved->filter(
            fn (LeaveRequest $item) => $item->leaveType?->isHoliday(),
        );

        $sickness = $approved->filter(
            fn (LeaveRequest $item) => in_array(
                strtolower((string) $item->leaveType?->key),
                ['sick', 'sick-leave', 'sickness'],
                true,
            ),
        );

        $personRows = $people->map(function (User $person) use (
            $approved,
            $pending,
            $allowanceService,
            $to,
        ) {
            $personApproved = $approved->where('user_id', $person->id);
            $personPending = $pending->where('user_id', $person->id);
            $summary = $allowanceService->ensureYear($person, $to);

            return [
                'id' => $person->id,
                'name' => $person->name,
                'job_title' => $person->job_title,
                'avatar_url' => $person->avatar_url,
                'departments' => $person->departments->map(fn ($department) => [
                    'id' => $department->id,
                    'name' => $department->name,
                    'colour' => $department->colour,
                    'is_primary' => (bool) $department->pivot?->is_primary,
                ])->values(),
                'holiday_days' => round(
                    $personApproved
                        ->filter(fn ($item) => $item->leaveType?->isHoliday())
                        ->sum('duration_minutes')
                    / AllowanceService::MINUTES_PER_DAY,
                    2,
                ),
                'sick_days' => round(
                    $personApproved
                        ->filter(fn ($item) => in_array(
                            strtolower((string) $item->leaveType?->key),
                            ['sick', 'sick-leave', 'sickness'],
                            true,
                        ))
                        ->sum('duration_minutes')
                    / AllowanceService::MINUTES_PER_DAY,
                    2,
                ),
                'pending_requests' => $personPending->count(),
                'allowance' => [
                    'granted_days' => $summary['granted_days'],
                    'used_days' => $summary['used_days'],
                    'remaining_days' => $summary['remaining_days'],
                ],
            ];
        })->values();

        $departmentRows = $people
            ->flatMap(fn (User $person) => $person->departments->map(
                fn ($department) => [
                    'department' => $department,
                    'person_id' => $person->id,
                ],
            ))
            ->groupBy(fn ($row) => $row['department']->id)
            ->map(function ($rows) use ($approved) {
                $department = $rows->first()['department'];
                $ids = $rows->pluck('person_id')->unique();
                $items = $approved->whereIn('user_id', $ids);

                return [
                    'id' => $department->id,
                    'name' => $department->name,
                    'colour' => $department->colour,
                    'people' => $ids->count(),
                    'approved_days' => round(
                        $items->sum('duration_minutes')
                        / AllowanceService::MINUTES_PER_DAY,
                        2,
                    ),
                    'holiday_days' => round(
                        $items
                            ->filter(fn ($item) => $item->leaveType?->isHoliday())
                            ->sum('duration_minutes')
                        / AllowanceService::MINUTES_PER_DAY,
                        2,
                    ),
                ];
            })
            ->sortBy('name')
            ->values();

        $leaveTypeRows = $approved
            ->groupBy('leave_type_id')
            ->map(function ($items) {
                $type = $items->first()->leaveType;

                return [
                    'id' => $type?->id,
                    'label' => $type?->label ?: 'Unknown',
                    'colour' => $type?->colour ?: '#777777',
                    'requests' => $items->count(),
                    'days' => round(
                        $items->sum('duration_minutes')
                        / AllowanceService::MINUTES_PER_DAY,
                        2,
                    ),
                ];
            })
            ->sortByDesc('days')
            ->values();

        $activity = AuditLog::query()
            ->where('organisation_id', $viewer->organisation_id)
            ->whereBetween('created_at', [$from, $to])
            ->with('actor')
            ->latest()
            ->limit(100)
            ->get()
            ->map(fn (AuditLog $log) => [
                'id' => $log->id,
                'action' => $log->action,
                'description' => $log->description,
                'actor' => $log->actor ? [
                    'id' => $log->actor->id,
                    'name' => $log->actor->name,
                ] : null,
                'created_at' => $log->created_at?->toIso8601String(),
            ])
            ->values();

        return response()->json([
            'data' => [
                'range' => [
                    'from' => $from->toDateString(),
                    'to' => $to->toDateString(),
                ],
                'summary' => [
                    'people' => $people->count(),
                    'allowance_highest_remaining' => $personRows
                        ->sortByDesc('allowance.remaining_days')
                        ->first(),
                    'allowance_lowest_remaining' => $personRows
                        ->sortBy('allowance.remaining_days')
                        ->first(),
                    'approved_requests' => $approved->count(),
                    'pending_requests' => $pending->count(),
                    'holiday_days' => round(
                        $holiday->sum('duration_minutes')
                        / AllowanceService::MINUTES_PER_DAY,
                        2,
                    ),
                    'sick_days' => round(
                        $sickness->sum('duration_minutes')
                        / AllowanceService::MINUTES_PER_DAY,
                        2,
                    ),
                    'manual_entries' => $approved->where('is_manual', true)->count(),
                ],
                'people' => $personRows,
                'departments' => $departmentRows,
                'leave_types' => $leaveTypeRows,
                'activity' => $activity,
            ],
        ]);
    }

    private function visibleUsers(User $viewer)
    {
        $query = User::query()
            ->where('organisation_id', $viewer->organisation_id)
            ->where('is_archived', false);

        if ($viewer->isAdministrator()) {
            return $query;
        }

        $managedDepartmentIds = $viewer->departments()
            ->wherePivot('is_manager', true)
            ->pluck('departments.id');

        return $query->whereHas('departments', function ($departmentQuery) use ($managedDepartmentIds) {
            $departmentQuery->whereIn('departments.id', $managedDepartmentIds);
        });
    }
}
