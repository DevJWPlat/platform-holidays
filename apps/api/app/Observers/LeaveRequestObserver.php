<?php

namespace App\Observers;

use App\Models\LeaveRequest;
use App\Services\AuditLogger;

class LeaveRequestObserver
{
    public function created(LeaveRequest $leaveRequest): void
    {
        $leaveRequest->loadMissing(['user', 'leaveType', 'creator']);

        $description = $leaveRequest->is_manual
            ? sprintf(
                '%s manually added %s for %s.',
                $leaveRequest->creator?->name ?: 'An administrator',
                $leaveRequest->leaveType?->label ?: 'leave',
                $leaveRequest->user?->name ?: 'a person',
            )
            : sprintf(
                '%s requested %s.',
                $leaveRequest->user?->name ?: 'A user',
                $leaveRequest->leaveType?->label ?: 'leave',
            );

        AuditLogger::log(
            organisationId: (int) $leaveRequest->user?->organisation_id,
            action: $leaveRequest->is_manual ? 'leave.manual_added' : 'leave.requested',
            description: $description,
            subject: $leaveRequest,
            metadata: [
                'status' => $leaveRequest->status,
                'starts_on' => $leaveRequest->starts_on?->toDateString(),
                'ends_on' => $leaveRequest->ends_on?->toDateString(),
            ],
            actorId: $leaveRequest->created_by ?: $leaveRequest->user_id,
        );
    }

    public function updated(LeaveRequest $leaveRequest): void
    {
        if (! $leaveRequest->wasChanged('status')) {
            return;
        }

        $leaveRequest->loadMissing(['user', 'reviewer', 'leaveType']);

        $action = match ($leaveRequest->status) {
            LeaveRequest::STATUS_APPROVED => 'leave.approved',
            LeaveRequest::STATUS_REJECTED => 'leave.rejected',
            LeaveRequest::STATUS_CANCELLED => 'leave.cancelled',
            default => 'leave.status_changed',
        };

        $description = match ($leaveRequest->status) {
            LeaveRequest::STATUS_APPROVED => sprintf(
                '%s approved %s for %s.',
                $leaveRequest->reviewer?->name ?: 'An approver',
                $leaveRequest->leaveType?->label ?: 'leave',
                $leaveRequest->user?->name ?: 'a person',
            ),
            LeaveRequest::STATUS_REJECTED => sprintf(
                '%s rejected %s for %s.',
                $leaveRequest->reviewer?->name ?: 'An approver',
                $leaveRequest->leaveType?->label ?: 'leave',
                $leaveRequest->user?->name ?: 'a person',
            ),
            LeaveRequest::STATUS_CANCELLED => sprintf(
                '%s cancelled %s.',
                $leaveRequest->user?->name ?: 'A user',
                $leaveRequest->leaveType?->label ?: 'leave',
            ),
            default => sprintf(
                '%s changed leave status to %s.',
                $leaveRequest->reviewer?->name ?: $leaveRequest->user?->name ?: 'A user',
                $leaveRequest->status,
            ),
        };

        AuditLogger::log(
            organisationId: (int) $leaveRequest->user?->organisation_id,
            action: $action,
            description: $description,
            subject: $leaveRequest,
            metadata: [
                'status' => $leaveRequest->status,
                'review_note' => $leaveRequest->review_note,
            ],
            actorId: $leaveRequest->reviewed_by ?: auth()->id() ?: $leaveRequest->user_id,
        );
    }
}
