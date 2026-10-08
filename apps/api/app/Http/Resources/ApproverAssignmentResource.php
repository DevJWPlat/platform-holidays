<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ApproverAssignmentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'priority' => $this->priority,
            'effective_from' => $this->effective_from?->toDateString(),
            'effective_until' => $this->effective_until?->toDateString(),

            'employee' => $this->whenLoaded('employee', fn () => $this->employee ? [
                'id' => $this->employee->id,
                'name' => $this->employee->name,
                'job_title' => $this->employee->job_title,
                'avatar_url' => $this->employee->avatar_url,
            ] : null),

            'department' => $this->whenLoaded('department', fn () => $this->department ? [
                'id' => $this->department->id,
                'name' => $this->department->name,
                'colour' => $this->department->colour,
            ] : null),

            'approver' => $this->whenLoaded('approver', fn () => $this->approver ? [
                'id' => $this->approver->id,
                'name' => $this->approver->name,
                'job_title' => $this->approver->job_title,
                'avatar_url' => $this->approver->avatar_url,
                'role' => $this->approver->role,
            ] : null),

            'scope_type' => $this->employee_id ? 'person' : 'department',
        ];
    }
}
