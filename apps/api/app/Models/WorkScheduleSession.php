<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WorkScheduleSession extends Model
{
    use HasFactory;

    protected $fillable = [
        'work_schedule_id',
        'week_number',
        'weekday',
        'session',
        'starts_at',
        'ends_at',
        'duration_minutes',
    ];

    protected function casts(): array
    {
        return [
            'week_number' => 'integer',
            'weekday' => 'integer',
            'duration_minutes' => 'integer',
        ];
    }

    public function workSchedule(): BelongsTo
    {
        return $this->belongsTo(WorkSchedule::class);
    }
}
