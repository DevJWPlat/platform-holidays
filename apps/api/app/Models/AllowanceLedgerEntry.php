<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AllowanceLedgerEntry extends Model
{
    use HasFactory;

    public const TYPE_BASE_GRANT = 'base_grant';
    public const TYPE_SERVICE_INCREMENT = 'service_increment';
    public const TYPE_CARRY_OVER = 'carry_over';
    public const TYPE_MANUAL_ADJUSTMENT = 'manual_adjustment';
    public const TYPE_BOOKING_DEDUCTION = 'booking_deduction';
    public const TYPE_BOOKING_REVERSAL = 'booking_reversal';

    protected $fillable = [
        'user_id',
        'leave_year_start',
        'leave_year_end',
        'entry_type',
        'minutes',
        'effective_date',
        'reference_type',
        'reference_id',
        'source_key',
        'note',
        'metadata',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'leave_year_start' => 'date',
            'leave_year_end' => 'date',
            'effective_date' => 'date',
            'minutes' => 'integer',
            'metadata' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
