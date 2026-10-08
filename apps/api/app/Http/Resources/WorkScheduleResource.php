<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class WorkScheduleResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'pattern_type' => $this->pattern_type,
            'is_default' => $this->is_default,
            'is_active' => $this->is_active,
            'sessions' => $this->whenLoaded('sessions', function () {
                return $this->sessions
                    ->sortBy(['week_number', 'weekday', 'starts_at'])
                    ->values()
                    ->map(fn ($session) => [
                        'id' => $session->id,
                        'week_number' => $session->week_number,
                        'weekday' => $session->weekday,
                        'session' => $session->session,
                        'starts_at' => $session->starts_at,
                        'ends_at' => $session->ends_at,
                        'duration_minutes' => $session->duration_minutes,
                    ]);
            }),
        ];
    }
}
