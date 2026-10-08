<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WorkScheduleException extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'date',
        'is_working_day',
        'working_minutes',
        'reason',
    ];

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'is_working_day' => 'boolean',
            'working_minutes' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
