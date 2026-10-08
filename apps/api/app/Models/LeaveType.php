<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class LeaveType extends Model
{
    use HasFactory, SoftDeletes;

    public const HOLIDAY_KEY = 'holiday';

    protected $fillable = [
        'organisation_id',
        'label',
        'key',
        'colour',
        'icon',
        'icon_colour',
        'is_system',
        'is_protected_holiday',
        'visibility',
        'requires_approval',
        'allow_half_days',
        'include_in_staffing_limits',
        'annual_usage_limit_minutes',
        'external_availability',
        'is_active',
        'display_order',
    ];

    protected function casts(): array
    {
        return [
            'is_system' => 'boolean',
            'is_protected_holiday' => 'boolean',
            'requires_approval' => 'boolean',
            'allow_half_days' => 'boolean',
            'include_in_staffing_limits' => 'boolean',
            'is_active' => 'boolean',
            'annual_usage_limit_minutes' => 'integer',
            'display_order' => 'integer',
        ];
    }

    public function organisation(): BelongsTo
    {
        return $this->belongsTo(Organisation::class);
    }

    public function leaveRequests(): HasMany
    {
        return $this->hasMany(LeaveRequest::class);
    }

    public function isHoliday(): bool
    {
        return $this->key === self::HOLIDAY_KEY && $this->is_protected_holiday;
    }
}
