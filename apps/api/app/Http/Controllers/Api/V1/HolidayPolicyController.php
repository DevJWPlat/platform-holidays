<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\OrganisationSetting;
use App\Services\AllowanceService;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class HolidayPolicyController extends Controller
{
    public function show(
        Request $request,
        AllowanceService $allowanceService,
    ): JsonResponse {
        $viewer = $request->user();
        abort_unless($viewer->isAdministrator(), 403);

        return response()->json([
            'data' => $allowanceService->policy(
                $viewer->organisation()->firstOrFail(),
            ),
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $viewer = $request->user();
        abort_unless($viewer->isAdministrator(), 403);

        $validated = $request->validate([
            'base_allowance_days' => ['required', 'integer', 'min:0', 'max:365'],
            'service_increment_enabled' => ['required', 'boolean'],
            'service_increment_days' => ['required', 'integer', 'min:0', 'max:365'],
            'service_increment_after_years' => ['required', 'integer', 'min:0', 'max:100'],
            'maximum_allowance_days' => ['nullable', 'integer', 'min:0', 'max:365'],
            'carry_over_enabled' => ['required', 'boolean'],
            'maximum_carry_over_days' => ['nullable', 'integer', 'min:0', 'max:365'],
        ]);

        $organisation = $viewer->organisation()->firstOrFail();

        $value = [
            'base_allowance_days' => (float) $validated['base_allowance_days'],
            'service_increment_enabled' =>
                (bool) $validated['service_increment_enabled'],
            'service_increment_days' =>
                (float) $validated['service_increment_days'],
            'service_increment_after_years' =>
                (int) $validated['service_increment_after_years'],
            'maximum_allowance_days' =>
                $validated['maximum_allowance_days'] === null
                    ? null
                    : (float) $validated['maximum_allowance_days'],
            'carry_over_enabled' =>
                (bool) $validated['carry_over_enabled'],
            'maximum_carry_over_days' =>
                $validated['maximum_carry_over_days'] === null
                    ? null
                    : (float) $validated['maximum_carry_over_days'],
        ];

        OrganisationSetting::query()->updateOrCreate(
            [
                'organisation_id' => $organisation->id,
                'key' => 'holiday_allowance_policy',
            ],
            ['value' => $value],
        );

        AuditLogger::log(
            organisationId: (int) $organisation->id,
            action: 'holiday_policy.updated',
            description: sprintf(
                '%s updated the holiday allowance policy.',
                $viewer->name,
            ),
            metadata: $value,
            actorId: $viewer->id,
        );

        return response()->json(['data' => $value]);
    }
}
