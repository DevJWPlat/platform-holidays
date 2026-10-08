<?php

namespace App\Services;

use App\Models\Department;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\StaffingGroup;
use App\Models\User;

class StaffingConflictService
{
    public function check(
        User $user,
        LeaveType $leaveType,
        array $breakdown,
        bool $isManual = false,
    ): array {
        if ($isManual || $this->isSickLeave($leaveType) || ! $leaveType->include_in_staffing_limits) {
            return [];
        }

        $dates = collect($breakdown)
            ->pluck('date')
            ->filter()
            ->unique()
            ->values();

        if ($dates->isEmpty()) {
            return [];
        }

        $conflicts = [];

        $departments = $user->departments()
            ->whereNotNull('maximum_absent')
            ->where('maximum_absent', '>', 0)
            ->get();

        foreach ($departments as $department) {
            foreach ($dates as $date) {
                $away = $this->awayUserIdsForDepartment($department, $date, $user->id);

                if (($away->count() + 1) > (int) $department->maximum_absent) {
                    $conflicts[] = [
                        'type' => 'department',
                        'rule_id' => $department->id,
                        'rule_name' => $department->name,
                        'date' => $date,
                        'maximum_absent' => (int) $department->maximum_absent,
                        'already_away' => $away->count(),
                        'message' => sprintf(
                            '%s already has %d %s away on %s. The maximum is %d.',
                            $department->name,
                            $away->count(),
                            $away->count() === 1 ? 'person' : 'people',
                            $date,
                            $department->maximum_absent,
                        ),
                    ];
                }
            }
        }

        $groups = $user->staffingGroups()
            ->where('is_active', true)
            ->whereNotNull('maximum_absent')
            ->where('maximum_absent', '>', 0)
            ->get();

        foreach ($groups as $group) {
            foreach ($dates as $date) {
                $away = $this->awayUserIdsForGroup($group, $date, $user->id);

                if (($away->count() + 1) > (int) $group->maximum_absent) {
                    $conflicts[] = [
                        'type' => 'staffing_group',
                        'rule_id' => $group->id,
                        'rule_name' => $group->name,
                        'date' => $date,
                        'maximum_absent' => (int) $group->maximum_absent,
                        'already_away' => $away->count(),
                        'message' => sprintf(
                            '%s requires at least %d member%s available. Another member is already away on %s.',
                            $group->name,
                            max(1, $group->users()->count() - (int) $group->maximum_absent),
                            max(1, $group->users()->count() - (int) $group->maximum_absent) === 1 ? '' : 's',
                            $date,
                        ),
                    ];
                }
            }
        }

        return collect($conflicts)
            ->unique(fn ($item) => $item['type'] . ':' . $item['rule_id'] . ':' . $item['date'])
            ->values()
            ->all();
    }

    private function awayUserIdsForDepartment(
        Department $department,
        string $date,
        int $excludeUserId,
    ) {
        $memberIds = $department->users()
            ->where('users.id', '!=', $excludeUserId)
            ->pluck('users.id');

        return $this->awayUserIds($memberIds->all(), $date);
    }

    private function awayUserIdsForGroup(
        StaffingGroup $group,
        string $date,
        int $excludeUserId,
    ) {
        $memberIds = $group->users()
            ->where('users.id', '!=', $excludeUserId)
            ->pluck('users.id');

        return $this->awayUserIds($memberIds->all(), $date);
    }

    private function awayUserIds(array $userIds, string $date)
    {
        if (! count($userIds)) {
            return collect();
        }

        return LeaveRequest::query()
            ->whereIn('user_id', $userIds)
            ->whereIn('status', [
                LeaveRequest::STATUS_PENDING,
                LeaveRequest::STATUS_APPROVED,
            ])
            ->where('is_manual', false)
            ->whereDate('starts_on', '<=', $date)
            ->whereDate('ends_on', '>=', $date)
            ->whereHas('leaveType', function ($query) {
                $query
                    ->where('include_in_staffing_limits', true)
                    ->whereNotIn('key', ['sick', 'sick-leave', 'sickness']);
            })
            ->distinct()
            ->pluck('user_id');
    }

    private function isSickLeave(LeaveType $leaveType): bool
    {
        return in_array(
            strtolower((string) $leaveType->key),
            ['sick', 'sick-leave', 'sickness'],
            true,
        );
    }
}
