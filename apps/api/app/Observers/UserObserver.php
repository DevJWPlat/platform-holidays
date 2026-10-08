<?php

namespace App\Observers;

use App\Models\User;
use App\Services\AuditLogger;

class UserObserver
{
    public function created(User $user): void
    {
        if (! $user->organisation_id) return;

        AuditLogger::log(
            organisationId: (int) $user->organisation_id,
            action: 'person.created',
            description: sprintf('%s was added to Platform Holidays.', $user->name),
            subject: $user,
        );
    }

    public function updated(User $user): void
    {
        if (! $user->organisation_id) return;

        $changed = collect(array_keys($user->getChanges()))
            ->intersect([
                'name',
                'email',
                'job_title',
                'role',
                'employment_start_date',
                'leaving_date',
                'is_archived',
                'can_override_staffing_limits',
            ])
            ->values();

        if ($changed->isEmpty()) return;

        AuditLogger::log(
            organisationId: (int) $user->organisation_id,
            action: 'person.updated',
            description: sprintf('%s was updated.', $user->name),
            subject: $user,
            metadata: ['changed' => $changed->all()],
        );
    }
}
