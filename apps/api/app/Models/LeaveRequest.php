<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LeaveRequest extends Model
{
    use HasFactory;

    public const STATUS_PENDING = 'pending';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_REJECTED = 'rejected';
    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'user_id',
        'created_by',
        'leave_type_id',
        'starts_on',
        'start_session',
        'ends_on',
        'end_session',
        'duration_minutes',
        'status',
        'reason',
        'is_manual',
        'staffing_override',
        'staffing_override_by',
        'staffing_override_reason',
        'reviewed_by',
        'reviewed_at',
        'review_note',
        'cancelled_at',
    ];

    protected function casts(): array
    {
        return [
            'starts_on' => 'date',
            'ends_on' => 'date',
            'duration_minutes' => 'integer',
            'is_manual' => 'boolean',
            'staffing_override' => 'boolean',
            'reviewed_at' => 'datetime',
            'cancelled_at' => 'datetime',
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

    public function leaveType(): BelongsTo
    {
        return $this->belongsTo(LeaveType::class);
    }

    public function staffingOverrideBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'staffing_override_by');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}
