<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

#[Fillable(['name', 'email',
        'google_id', 'password'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'employment_start_date' => 'date',
            'holiday_allowance_override_days' => 'decimal:2',
            'carry_over_override' => 'boolean',
            'carry_over_max_days_override' => 'integer',
            'leaving_date' => 'date',
            'date_of_birth' => 'date',
            'is_archived' => 'boolean',
            'can_override_staffing_limits' => 'boolean',
            'archived_at' => 'datetime',
            'last_login_at' => 'datetime',
        ];
    }
    public function organisation(): BelongsTo
    {
        return $this->belongsTo(Organisation::class);
    }

    public function departments(): BelongsToMany
    {
        return $this->belongsToMany(Department::class)
            ->withPivot(['is_primary', 'is_manager'])
            ->withTimestamps();
    }

    public function staffingGroups(): BelongsToMany
    {
        return $this->belongsToMany(StaffingGroup::class)
            ->withTimestamps();
    }

    public function leaveRequests(): HasMany
    {
        return $this->hasMany(LeaveRequest::class);
    }

    public function allowanceLedgerEntries(): HasMany
    {
        return $this->hasMany(AllowanceLedgerEntry::class);
    }

    public function approverAssignments(): HasMany
    {
        return $this->hasMany(ApproverAssignment::class, 'approver_id');
    }

    public function assignedApprovers(): HasMany
    {
        return $this->hasMany(ApproverAssignment::class, 'employee_id');
    }

    public function canOverrideStaffingLimits(): bool
    {
        return (bool) $this->can_override_staffing_limits || $this->isAdministrator();
    }

    public function isAdministrator(): bool
    {
        return $this->role === 'administrator';
    }

    public function isDepartmentManager(): bool
    {
        return $this->role === 'department_manager';
    }

    public function isApprover(): bool
    {
        return in_array($this->role, [
            'approver',
            'department_manager',
            'administrator',
        ], true);
    }
}

