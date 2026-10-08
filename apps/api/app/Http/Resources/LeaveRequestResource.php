<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class LeaveRequestResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'status' => $this->status,
            'starts_on' => $this->starts_on?->toDateString(),
            'start_session' => $this->start_session,
            'ends_on' => $this->ends_on?->toDateString(),
            'end_session' => $this->end_session,
            'duration_minutes' => $this->duration_minutes,
            'duration_days' => round($this->duration_minutes / 420, 2),
            'reason' => $this->reason,
            'created_by' => $this->whenLoaded('creator', fn () => $this->creator ? [
                'id' => $this->creator->id,
                'name' => $this->creator->name,
            ] : null),
            'is_manual' => (bool) $this->is_manual,
            'staffing_override' => (bool) $this->staffing_override,
            'staffing_override_reason' => $this->staffing_override_reason,
            'review_note' => $this->review_note,
            'reviewed_at' => $this->reviewed_at?->toIso8601String(),
            'cancelled_at' => $this->cancelled_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),

            'leave_type' => $this->whenLoaded('leaveType', fn () => [
                'id' => $this->leaveType->id,
                'label' => $this->leaveType->label,
                'key' => $this->leaveType->key,
                'colour' => $this->leaveType->colour,
                'icon' => $this->leaveType->icon,
                'icon_colour' => $this->leaveType->icon_colour ?: 'white',
                'is_protected_holiday' => $this->leaveType->is_protected_holiday,
                'requires_approval' => $this->leaveType->requires_approval,
            ]),

            'user' => $this->whenLoaded('user', fn () => [
                'id' => $this->user->id,
                'name' => $this->user->name,
                'email' => $this->user->email,
                'job_title' => $this->user->job_title,
                'avatar_url' => $this->user->avatar_url,
                'departments' => DepartmentResource::collection(
                    $this->user->relationLoaded('departments')
                        ? $this->user->departments
                        : collect(),
                ),
            ]),

            'reviewer' => $this->whenLoaded('reviewer', fn () => $this->reviewer ? [
                'id' => $this->reviewer->id,
                'name' => $this->reviewer->name,
            ] : null),

            'can_cancel' => $this->user_id === $request->user()?->id
                && in_array($this->status, ['pending', 'approved'], true)
                && ! $this->starts_on?->isBefore(today()),
        ];
    }
}
