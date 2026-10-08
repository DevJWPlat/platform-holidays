<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Department extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'organisation_id',
        'name',
        'slug',
        'colour',
        'maximum_absent',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'maximum_absent' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function organisation(): BelongsTo
    {
        return $this->belongsTo(Organisation::class);
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class)
            ->withPivot(['is_primary', 'is_manager'])
            ->withTimestamps();
    }

    public function approverAssignments(): HasMany
    {
        return $this->hasMany(ApproverAssignment::class);
    }
}
