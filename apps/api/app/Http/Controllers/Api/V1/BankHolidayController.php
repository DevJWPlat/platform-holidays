<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\BankHolidayService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BankHolidayController extends Controller
{
    public function index(Request $request, BankHolidayService $bankHolidayService): JsonResponse
    {
        $user = $request->user();

        $validated = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
        ]);

        $from = isset($validated['from'])
            ? CarbonImmutable::parse($validated['from'])
            : now()->startOfYear()->subYear()->toImmutable();

        $to = isset($validated['to'])
            ? CarbonImmutable::parse($validated['to'])
            : now()->endOfYear()->addYears(2)->toImmutable();

        $events = collect($bankHolidayService->events(
            $bankHolidayService->divisionFor($user),
        ))
            ->filter(function ($event) use ($from, $to) {
                if (! isset($event['date'])) {
                    return false;
                }

                $date = CarbonImmutable::parse($event['date']);

                return $date->betweenIncluded($from, $to);
            })
            ->values()
            ->all();

        return response()->json([
            'data' => $events,
        ]);
    }
}
