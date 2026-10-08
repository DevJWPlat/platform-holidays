<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class StaffingGroupResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'maximum_absent' => $this->maximum_absent,
            'is_active' => $this->is_active,
            'members' => $this->whenLoaded('users', fn () => $this->users->map(fn ($user) => [
                'id' => $user->id,
                'name' => $user->name,
                'job_title' => $user->job_title,
                'avatar_url' => $user->avatar_url,
            ])->values()),
        ];
    }
}
