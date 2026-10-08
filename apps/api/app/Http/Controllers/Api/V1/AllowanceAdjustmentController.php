<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AllowanceLedgerEntry;
use App\Models\User;
use App\Services\AllowanceService;
use App\Services\AuditLogger;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class AllowanceAdjustmentController extends Controller
{
    public function show(
        Request $request,
        User $person,
        AllowanceService $allowanceService,
    ): JsonResponse {
        $viewer = $request->user();
        abort_unless($viewer->isAdministrator(), 403);
        abort_unless($person->organisation_id === $viewer->organisation_id, 404);

        $anchor = $request->filled('date')
            ? CarbonImmutable::parse((string) $request->query('date'))
            : CarbonImmutable::now(
                $viewer->organisation?->timezone ?: 'Europe/London',
            );

        return response()->json([
            'data' => $allowanceService->ensureYear($person, $anchor),
        ]);
    }

    public function store(
        Request $request,
        User $person,
        AllowanceService $allowanceService,
    ): JsonResponse {
        $viewer = $request->user();
        abort_unless($viewer->isAdministrator(), 403);
        abort_unless($person->organisation_id === $viewer->organisation_id, 404);

        $validated = $request->validate([
            'days' => ['required', 'integer', 'not_in:0', 'min:-365', 'max:365'],
            'reason' => ['required', 'string', 'max:2000'],
            'date' => ['nullable', 'date'],
        ]);

        $anchor = ($validated['date'] ?? null)
            ? CarbonImmutable::parse($validated['date'])
            : CarbonImmutable::now(
                $viewer->organisation?->timezone ?: 'Europe/London',
            );

        $summaryBefore = $allowanceService->ensureYear($person, $anchor);
        $year = $summaryBefore['leave_year'];
        $minutes = (int) round(
            (float) $validated['days'] * AllowanceService::MINUTES_PER_DAY,
        );

        AllowanceLedgerEntry::query()->create([
            'user_id' => $person->id,
            'leave_year_start' => $year['start'],
            'leave_year_end' => $year['end'],
            'entry_type' => AllowanceLedgerEntry::TYPE_MANUAL_ADJUSTMENT,
            'minutes' => $minutes,
            'effective_date' => $anchor->toDateString(),
            'source_key' => 'manual-adjustment-' . Str::uuid(),
            'note' => trim($validated['reason']),
            'metadata' => [
                'days' => (float) $validated['days'],
                'adjusted_by' => $viewer->id,
                'adjusted_by_name' => $viewer->name,
            ],
        ]);

        $summaryAfter = $allowanceService->summary($person, $anchor);

        AuditLogger::log(
            organisationId: (int) $viewer->organisation_id,
            action: 'allowance.adjusted',
            description: sprintf(
                '%s adjusted %s holiday allowance by %s days.',
                $viewer->name,
                $person->name,
                number_format((float) $validated['days'], 2),
            ),
            subject: $person,
            metadata: [
                'days' => (float) $validated['days'],
                'reason' => trim($validated['reason']),
                'remaining_before_days' =>
                    $summaryBefore['remaining_days'],
                'remaining_after_days' =>
                    $summaryAfter['remaining_days'],
            ],
            actorId: $viewer->id,
        );

        return response()->json([
            'data' => $summaryAfter,
        ], 201);
    }
}
