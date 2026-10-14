<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class GroupBooking extends Model
{
    use HasFactory;

    protected $fillable = [
        'organisation_id', 'leave_type_id', 'created_by', 'scope_type',
        'department_id', 'starts_on', 'ends_on', 'start_session',
        'end_session', 'reason',
    ];

    protected function casts(): array
    {
        return ['starts_on' => 'date', 'ends_on' => 'date'];
    }

    public function leaveType(): BelongsTo { return $this->belongsTo(LeaveType::class); }
    public function creator(): BelongsTo { return $this->belongsTo(User::class, 'created_by'); }
    public function department(): BelongsTo { return $this->belongsTo(Department::class); }
    public function leaveRequests(): HasMany { return $this->hasMany(LeaveRequest::class); }
}
