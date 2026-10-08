<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\CompanyClosureService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CompanyClosureController extends Controller
{
    public function index(
        Request $request,
        CompanyClosureService $service,
    ): JsonResponse {
        return response()->json([
            'data' => $service->closures(
                (int) $request->user()->organisation_id,
            ),
        ]);
    }

    public function store(
        Request $request,
        CompanyClosureService $service,
    ): JsonResponse {
        $viewer = $request->user();

        abort_unless(
            $viewer->isAdministrator(),
            403,
            'Only administrators can manage company closures.',
        );

        $validated = $request->validate([
            'starts_on' => ['required', 'date'],
            'ends_on' => [
                'required',
                'date',
                'after_or_equal:starts_on',
            ],
        ]);

        $item = $service->add(
            (int) $viewer->organisation_id,
            $validated['starts_on'],
            $validated['ends_on'],
        );

        return response()->json([
            'data' => $item,
            'message' => 'Festive Break saved.',
        ], 201);
    }

    public function destroy(
        Request $request,
        string $closureId,
        CompanyClosureService $service,
    ): JsonResponse {
        $viewer = $request->user();

        abort_unless(
            $viewer->isAdministrator(),
            403,
            'Only administrators can manage company closures.',
        );

        $service->remove(
            (int) $viewer->organisation_id,
            $closureId,
        );

        return response()->json([
            'message' => 'Festive Break removed.',
        ]);
    }
}
