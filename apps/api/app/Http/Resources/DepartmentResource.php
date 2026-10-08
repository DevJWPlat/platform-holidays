<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DepartmentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'colour' => $this->colour,
            'maximum_absent' => $this->maximum_absent,
            'is_active' => $this->is_active,
            'member_count' => $this->whenCounted('users'),
            'members' => $this->whenLoaded('users', fn () => $this->users->map(fn ($user) => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'job_title' => $user->job_title,
                'avatar_url' => $user->avatar_url,
                'pivot' => [
                    'is_primary' => (bool) $user->pivot?->is_primary,
                    'is_manager' => (bool) $user->pivot?->is_manager,
                ],
            ])->values()),
        ];
    }
}
