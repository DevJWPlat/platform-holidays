<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PersonResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $viewer = $request->user();
        $canManagePeople = $viewer
            && ($viewer->isDepartmentManager() || $viewer->isAdministrator());
        $canManageSettings = $viewer && $viewer->isAdministrator();

        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'job_title' => $this->job_title,
            'date_of_birth' => $this->date_of_birth?->toDateString(),
            'employment_start_date' => $this->employment_start_date?->toDateString(),
            'avatar_url' => $this->avatar_url,
            'role' => $this->role,
            'allowance_unit' => $this->allowance_unit,
            'holiday_allowance_override_days' => $this->holiday_allowance_override_days !== null
                ? (float) $this->holiday_allowance_override_days
                : null,
            'carry_over_override' => $this->carry_over_override,
            'carry_over_max_days_override' => $this->carry_over_max_days_override,
            'is_leadership' => $this->relationLoaded('staffingGroups')
                ? $this->staffingGroups->contains('slug', 'leadership-cover')
                : false,
            'invite_url' => $this->when(
                $canManageSettings,
                fn () => rtrim(config('app.frontend_url', 'http://localhost:5173'), '/')
                    . '/login?invite=' . urlencode($this->email),
            ),
            'bank_holiday_division' => $this->bank_holiday_division,
            'is_archived' => $this->is_archived,
            'employment_start_date' => $this->employment_start_date?->toDateString(),
            'can_override_staffing_limits' => (bool) $this->can_override_staffing_limits,
            'leaving_date' => $this->leaving_date?->toDateString(),
            'departments' => DepartmentResource::collection($this->whenLoaded('departments')),

            'work_schedule' => $this->when(
                $canManagePeople,
                fn () => $this->resource->getAttribute('current_schedule_summary'),
            ),

            'allowance' => $this->when(
                $canManagePeople,
                fn () => $this->resource->getAttribute('allowance_summary'),
            ),
        ];
    }
}
