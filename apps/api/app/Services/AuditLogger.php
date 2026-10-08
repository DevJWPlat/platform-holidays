<?php

namespace App\Services;

use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Model;

class AuditLogger
{
    public static function log(
        int $organisationId,
        string $action,
        string $description,
        ?Model $subject = null,
        array $metadata = [],
        ?int $actorId = null,
    ): void {
        try {
            AuditLog::query()->create([
                'organisation_id' => $organisationId,
                'actor_id' => $actorId ?? auth()->id(),
                'action' => $action,
                'subject_type' => $subject ? $subject::class : null,
                'subject_id' => $subject?->getKey(),
                'description' => $description,
                'metadata' => $metadata ?: null,
            ]);
        } catch (\Throwable $exception) {
            report($exception);
        }
    }
}
