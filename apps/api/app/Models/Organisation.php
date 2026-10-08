<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Organisation extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'slug',
        'timezone',
        'leave_year_start_month',
        'leave_year_start_day',
        'default_bank_holiday_division',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'leave_year_start_month' => 'integer',
            'leave_year_start_day' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function departments(): HasMany
    {
        return $this->hasMany(Department::class);
    }

    public function settings(): HasMany
    {
        return $this->hasMany(OrganisationSetting::class);
    }

    public function approverAssignments(): HasMany
    {
        return $this->hasMany(ApproverAssignment::class);
    }
}
