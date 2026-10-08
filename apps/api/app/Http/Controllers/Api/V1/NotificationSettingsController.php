<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\OrganisationSetting;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NotificationSettingsController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $viewer = $request->user();
        abort_unless($viewer->isAdministrator(), 403);

        $setting = OrganisationSetting::query()
            ->where('organisation_id', $viewer->organisation_id)
            ->where('key', 'notifications')
            ->first();

        return response()->json([
            'data' => array_merge($this->defaults(), $setting?->value ?? []),
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $viewer = $request->user();
        abort_unless($viewer->isAdministrator(), 403);

        $validated = $request->validate([
            'birthday_enabled' => ['required', 'boolean'],
            'birthday_days_before' => ['required', 'array', 'min:1'],
            'birthday_days_before.*' => ['integer', 'min:0', 'max:365'],

            'anniversary_enabled' => ['required', 'boolean'],
            'anniversary_days_before' => ['required', 'array', 'min:1'],
            'anniversary_days_before.*' => ['integer', 'min:0', 'max:365'],

            'recipient_user_ids' => ['required', 'array', 'min:1'],
            'recipient_user_ids.*' => ['integer'],

            'send_hour' => ['required', 'integer', 'min:0', 'max:23'],

            'leave_request_submitted_email' => ['required', 'boolean'],
            'leave_request_approved_email' => ['required', 'boolean'],
            'leave_request_rejected_email' => ['required', 'boolean'],
        ]);

        $allowedRecipientIds = User::query()
            ->where('organisation_id', $viewer->organisation_id)
            ->where('is_archived', false)
            ->whereIn('id', $validated['recipient_user_ids'])
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->values()
            ->all();

        $value = [
            'birthday_enabled' => (bool) $validated['birthday_enabled'],
            'birthday_days_before' => collect($validated['birthday_days_before'])
                ->map(fn ($value) => (int) $value)
                ->unique()
                ->sortDesc()
                ->values()
                ->all(),

            'anniversary_enabled' => (bool) $validated['anniversary_enabled'],
            'anniversary_days_before' => collect($validated['anniversary_days_before'])
                ->map(fn ($value) => (int) $value)
                ->unique()
                ->sortDesc()
                ->values()
                ->all(),

            'recipient_user_ids' => $allowedRecipientIds,
            'send_hour' => (int) $validated['send_hour'],

            'leave_request_submitted_email' =>
                (bool) $validated['leave_request_submitted_email'],
            'leave_request_approved_email' =>
                (bool) $validated['leave_request_approved_email'],
            'leave_request_rejected_email' =>
                (bool) $validated['leave_request_rejected_email'],
        ];

        OrganisationSetting::query()->updateOrCreate(
            [
                'organisation_id' => $viewer->organisation_id,
                'key' => 'notifications',
            ],
            ['value' => $value],
        );

        AuditLogger::log(
            organisationId: (int) $viewer->organisation_id,
            action: 'notifications.updated',
            description: sprintf(
                '%s updated notification settings.',
                $viewer->name,
            ),
            metadata: $value,
            actorId: $viewer->id,
        );

        return response()->json(['data' => $value]);
    }

    private function defaults(): array
    {
        return [
            'birthday_enabled' => true,
            'birthday_days_before' => [7, 0],
            'anniversary_enabled' => true,
            'anniversary_days_before' => [7, 0],
            'recipient_user_ids' => [],
            'send_hour' => 9,
            'leave_request_submitted_email' => true,
            'leave_request_approved_email' => true,
            'leave_request_rejected_email' => true,
        ];
    }
}
