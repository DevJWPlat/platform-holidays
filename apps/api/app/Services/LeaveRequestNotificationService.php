<?php

namespace App\Services;

use App\Mail\LeaveRequestMail;
use App\Models\ApproverAssignment;
use App\Models\LeaveRequest;
use App\Models\OrganisationSetting;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Mail;
use Throwable;

class LeaveRequestNotificationService
{
    public function submitted(LeaveRequest $leaveRequest): void
    {
        if (! $this->enabled(
            $leaveRequest,
            'leave_request_submitted_email',
            true,
        )) {
            return;
        }

        $leaveRequest->loadMissing([
            'user.departments',
            'leaveType',
        ]);

        foreach ($this->approversFor($leaveRequest) as $recipient) {
            $this->sendSafely(
                $recipient,
                $leaveRequest,
                'submitted',
            );
        }
    }

    public function approved(
        LeaveRequest $leaveRequest,
        User $reviewer,
    ): void {
        if (! $this->enabled(
            $leaveRequest,
            'leave_request_approved_email',
            true,
        )) {
            return;
        }

        $leaveRequest->loadMissing(['user', 'leaveType']);

        if ($leaveRequest->user?->email) {
            $this->sendSafely(
                $leaveRequest->user,
                $leaveRequest,
                'approved',
                $reviewer,
            );
        }
    }

    public function rejected(
        LeaveRequest $leaveRequest,
        User $reviewer,
    ): void {
        if (! $this->enabled(
            $leaveRequest,
            'leave_request_rejected_email',
            true,
        )) {
            return;
        }

        $leaveRequest->loadMissing(['user', 'leaveType']);

        if ($leaveRequest->user?->email) {
            $this->sendSafely(
                $leaveRequest->user,
                $leaveRequest,
                'rejected',
                $reviewer,
            );
        }
    }

    private function enabled(
        LeaveRequest $leaveRequest,
        string $key,
        bool $default,
    ): bool {
        $organisationId = $leaveRequest->user?->organisation_id
            ?? $leaveRequest->user()->value('organisation_id');

        if (! $organisationId) {
            return false;
        }

        $setting = OrganisationSetting::query()
            ->where('organisation_id', $organisationId)
            ->where('key', 'notifications')
            ->first();

        $value = is_array($setting?->value)
            ? $setting->value
            : [];

        return array_key_exists($key, $value)
            ? (bool) $value[$key]
            : $default;
    }

    private function approversFor(
        LeaveRequest $leaveRequest,
    ): Collection {
        $employee = $leaveRequest->user;
        $organisationId = (int) $employee->organisation_id;
        $today = today()->toDateString();

        $activeRules = ApproverAssignment::query()
            ->where('organisation_id', $organisationId)
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

        $personSpecific = (clone $activeRules)
            ->where('employee_id', $employee->id)
            ->orderBy('priority')
            ->pluck('approver_id')
            ->filter()
            ->unique()
            ->values();

        if ($personSpecific->isNotEmpty()) {
            return $this->users($organisationId, $personSpecific);
        }

        $departmentIds = $employee->departments
            ->pluck('id')
            ->filter()
            ->values();

        if ($departmentIds->isNotEmpty()) {
            $departmentApprovers = (clone $activeRules)
                ->whereIn('department_id', $departmentIds)
                ->orderBy('priority')
                ->pluck('approver_id')
                ->filter()
                ->unique()
                ->values();

            if ($departmentApprovers->isNotEmpty()) {
                return $this->users(
                    $organisationId,
                    $departmentApprovers,
                );
            }

            $managerIds = User::query()
                ->where('organisation_id', $organisationId)
                ->where('is_archived', false)
                ->whereHas('departments', function ($query) use ($departmentIds) {
                    $query
                        ->whereIn('departments.id', $departmentIds)
                        ->where('department_user.is_manager', true);
                })
                ->pluck('id')
                ->unique()
                ->values();

            if ($managerIds->isNotEmpty()) {
                return $this->users(
                    $organisationId,
                    $managerIds,
                );
            }
        }

        return User::query()
            ->where('organisation_id', $organisationId)
            ->where('is_archived', false)
            ->where('role', 'administrator')
            ->whereNotNull('email')
            ->where('id', '!=', $employee->id)
            ->orderBy('name')
            ->get();
    }

    private function users(
        int $organisationId,
        Collection $ids,
    ): Collection {
        return User::query()
            ->where('organisation_id', $organisationId)
            ->where('is_archived', false)
            ->whereIn('id', $ids)
            ->whereNotNull('email')
            ->orderBy('name')
            ->get();
    }

    private function sendSafely(
        User $recipient,
        LeaveRequest $leaveRequest,
        string $event,
        ?User $actor = null,
    ): void {
        try {
            Mail::to($recipient->email)->send(
                new LeaveRequestMail(
                    leaveRequest: $leaveRequest,
                    event: $event,
                    actor: $actor,
                ),
            );
        } catch (Throwable $exception) {
            report($exception);
        }
    }
}
