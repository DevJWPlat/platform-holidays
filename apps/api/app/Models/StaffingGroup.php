<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class StaffingGroup extends Model
{
    use HasFactory;

    protected $fillable = [
        'organisation_id',
        'name',
        'slug',
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
            ->withTimestamps();
    }
}
