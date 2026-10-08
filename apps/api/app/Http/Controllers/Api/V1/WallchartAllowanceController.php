<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\AllowanceService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WallchartAllowanceController extends Controller
{
    public function index(
        Request $request,
        AllowanceService $allowanceService,
    ): JsonResponse {
        $viewer = $request->user();

        $validated = $request->validate([
            'date' => ['nullable', 'date'],
        ]);

        $timezone = $viewer->organisation?->timezone ?: 'Europe/London';

        $date = CarbonImmutable::parse(
            $validated['date'] ?? now($timezone)->toDateString(),
            $timezone,
        );

        $peopleQuery = User::query()
            ->where('organisation_id', $viewer->organisation_id)
            ->where('is_archived', false);

        $canSeeTeamBalances =
            $viewer->isAdministrator()
            || $viewer->isApprover();

        if (! $canSeeTeamBalances) {
            $peopleQuery->whereKey($viewer->id);
        }

        $people = $peopleQuery
            ->orderBy('name')
            ->get();

        $data = $people->map(function (User $person) use (
            $allowanceService,
            $date,
        ) {
            $summary = $allowanceService->ensureYear($person, $date);

            return [
                'user_id' => $person->id,
                'remaining_days' => (float) $summary['remaining_days'],
                'granted_days' => (float) $summary['granted_days'],
                'leave_year_start' => $summary['leave_year']['start'] ?? null,
                'leave_year_end' => $summary['leave_year']['end'] ?? null,
            ];
        })->values();

        return response()->json([
            'data' => $data,
        ]);
    }
}
