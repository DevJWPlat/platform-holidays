<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ApproverAssignment extends Model
{
    use HasFactory;

    protected $fillable = [
        'organisation_id',
        'employee_id',
        'department_id',
        'approver_id',
        'priority',
        'effective_from',
        'effective_until',
    ];

    protected function casts(): array
    {
        return [
            'priority' => 'integer',
            'effective_from' => 'date',
            'effective_until' => 'date',
        ];
    }

    public function organisation(): BelongsTo
    {
        return $this->belongsTo(Organisation::class);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'employee_id');
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approver_id');
    }
}
