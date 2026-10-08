<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\AllowanceService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AllowanceController extends Controller
{
    public function show(Request $request, AllowanceService $allowanceService): JsonResponse
    {
        $user = $request->user();

        $anchor = $request->filled('date')
            ? CarbonImmutable::parse((string) $request->query('date'))
            : CarbonImmutable::now($user->organisation?->timezone ?: 'Europe/London');

        return response()->json([
            'data' => $allowanceService->ensureYear($user, $anchor),
        ]);
    }
}
