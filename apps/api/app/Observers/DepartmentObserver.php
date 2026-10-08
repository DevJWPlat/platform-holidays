<?php

namespace App\Observers;

use App\Models\Department;
use App\Services\AuditLogger;

class DepartmentObserver
{
    public function created(Department $department): void
    {
        AuditLogger::log(
            organisationId: (int) $department->organisation_id,
            action: 'department.created',
            description: sprintf('Department %s was created.', $department->name),
            subject: $department,
            metadata: ['maximum_absent' => $department->maximum_absent],
        );
    }

    public function updated(Department $department): void
    {
        $changes = $department->getChanges();
        if (! count($changes)) return;

        AuditLogger::log(
            organisationId: (int) $department->organisation_id,
            action: 'department.updated',
            description: sprintf('Department %s was updated.', $department->name),
            subject: $department,
            metadata: ['changed' => array_keys($changes)],
        );
    }
}
