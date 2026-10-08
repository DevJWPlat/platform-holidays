<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\OrganisationSetting;
use App\Services\AuditLogger;
use App\Services\BankHolidayService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class OrganisationSettingsController extends Controller
{
    public function show(
        Request $request,
        BankHolidayService $bankHolidayService,
    ): JsonResponse {
        $viewer = $request->user();
        abort_unless($viewer->isAdministrator(), 403);

        $organisation = $viewer->organisation()->firstOrFail();
        $bankHolidaySettings = $bankHolidayService
            ->organisationSettings((int) $organisation->id);

        $events = collect(
            $bankHolidayService->events(
                $organisation->default_bank_holiday_division
                    ?: 'england-and-wales',
            ),
        )
            ->filter(fn ($event) => $event['date'] >= now()->subMonth()->toDateString())
            ->take(12)
            ->values()
            ->all();

        return response()->json([
            'data' => [
                'name' => $organisation->name,
                'timezone' => 'Europe/London',
                'leave_year_start_month' =>
                    (int) $organisation->leave_year_start_month,
                'leave_year_start_day' =>
                    (int) $organisation->leave_year_start_day,
                'default_bank_holiday_division' => 'england-and-wales',
                'exclude_bank_holidays_from_leave_duration' =>
                    (bool) $bankHolidaySettings['exclude_from_leave_duration'],
                'bank_holidays' => $events,
            ],
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $viewer = $request->user();
        abort_unless($viewer->isAdministrator(), 403);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'leave_year_start_month' => [
                'required',
                'integer',
                'min:1',
                'max:12',
            ],
            'leave_year_start_day' => [
                'required',
                'integer',
                'min:1',
                'max:31',
            ],
            'exclude_bank_holidays_from_leave_duration' => [
                'required',
                'boolean',
            ],
        ]);

        $organisation = $viewer->organisation()->firstOrFail();

        $organisation->update([
            'name' => trim($validated['name']),
            'timezone' => 'Europe/London',
            'leave_year_start_month' =>
                (int) $validated['leave_year_start_month'],
            'leave_year_start_day' =>
                (int) $validated['leave_year_start_day'],
            'default_bank_holiday_division' => 'england-and-wales',
        ]);

        OrganisationSetting::query()->updateOrCreate(
            [
                'organisation_id' => $organisation->id,
                'key' => 'bank_holidays',
            ],
            [
                'value' => [
                    'exclude_from_leave_duration' =>
                        (bool) $validated[
                            'exclude_bank_holidays_from_leave_duration'
                        ],
                ],
            ],
        );

        AuditLogger::log(
            organisationId: (int) $organisation->id,
            action: 'organisation.settings.updated',
            description: sprintf(
                '%s updated organisation settings.',
                $viewer->name,
            ),
            metadata: $validated,
            actorId: $viewer->id,
        );

        return response()->json([
            'data' => [
                ...$validated,
                'timezone' => 'Europe/London',
                'default_bank_holiday_division' => 'england-and-wales',
            ],
        ]);
    }
}
