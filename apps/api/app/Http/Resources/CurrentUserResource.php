<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CurrentUserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'job_title' => $this->job_title,
            'avatar_url' => $this->avatar_url,
            'role' => $this->role,
            'allowance_unit' => $this->allowance_unit,
            'bank_holiday_division' => $this->bank_holiday_division,
            'organisation' => $this->whenLoaded('organisation', fn () => [
                'id' => $this->organisation?->id,
                'name' => $this->organisation?->name,
                'slug' => $this->organisation?->slug,
                'timezone' => $this->organisation?->timezone,
            ]),
            'departments' => DepartmentResource::collection($this->whenLoaded('departments')),
            'permissions' => [
                'review_requests' => $this->isApprover(),
                'manage_department_people' => $this->isDepartmentManager() || $this->isAdministrator(),
                'view_reports' => $this->isDepartmentManager() || $this->isAdministrator(),
                'manage_settings' => $this->isAdministrator(),
                'override_staffing_limits' => $this->canOverrideStaffingLimits(),
            ],
        ];
    }
}
