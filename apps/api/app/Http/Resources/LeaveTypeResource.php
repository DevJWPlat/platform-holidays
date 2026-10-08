<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class LeaveTypeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'label' => $this->label,
            'key' => $this->key,
            'colour' => $this->colour,
            'icon' => $this->icon,
            'icon_colour' => $this->icon_colour ?: 'white',
            'is_system' => $this->is_system,
            'is_protected_holiday' => $this->is_protected_holiday,
            'visibility' => $this->visibility,
            'requires_approval' => $this->requires_approval,
            'allow_half_days' => $this->allow_half_days,
            'include_in_staffing_limits' => $this->include_in_staffing_limits,
            'annual_usage_limit_minutes' => $this->annual_usage_limit_minutes,
            'external_availability' => $this->external_availability,
            'is_active' => $this->is_active,
            'display_order' => $this->display_order,
        ];
    }
}
