<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HolidayBalanceReminderDispatch extends Model
{
    protected $fillable = [
        'user_id',
        'leave_year_start',
        'months_remaining',
        'sent_at',
    ];

    protected function casts(): array
    {
        return [
            'leave_year_start' => 'date',
            'months_remaining' => 'integer',
            'sent_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
