<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class CarryOverRuleController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $viewer = $request->user();

        abort_unless(
            $viewer->isAdministrator(),
            403,
            'Only administrators can manage carry-over rules.',
        );

        $people = User::query()
            ->where('organisation_id', $viewer->organisation_id)
            ->where('is_archived', false)
            ->orderBy('name')
            ->get()
            ->map(fn (User $person) => [
                'id' => $person->id,
                'name' => $person->name,
                'email' => $person->email,
                'job_title' => $person->job_title,
                'avatar_url' => $person->avatar_url,
                'mode' => $person->carry_over_override === null
                    ? 'organisation'
                    : ($person->carry_over_override ? 'allow' : 'block'),
                'maximum_days' => $person->carry_over_max_days_override,
            ])
            ->values();

        return response()->json([
            'data' => $people,
        ]);
    }

    public function update(
        Request $request,
        User $person,
    ): JsonResponse {
        $viewer = $request->user();

        abort_unless(
            $viewer->isAdministrator(),
            403,
            'Only administrators can manage carry-over rules.',
        );

        abort_unless(
            (int) $person->organisation_id
                === (int) $viewer->organisation_id,
            404,
        );

        $validated = $request->validate([
            'mode' => [
                'required',
                Rule::in(['organisation', 'allow', 'block']),
            ],
            'maximum_days' => [
                'nullable',
                'integer',
                'min:0',
                'max:365',
            ],
        ]);

        if (
            $validated['mode'] === 'allow'
            && $validated['maximum_days'] === null
        ) {
            return response()->json([
                'message' =>
                    'Set the maximum number of carry-over days.',
                'errors' => [
                    'maximum_days' => [
                        'Set the maximum number of carry-over days.',
                    ],
                ],
            ], 422);
        }

        $person->forceFill([
            'carry_over_override' => match ($validated['mode']) {
                'allow' => true,
                'block' => false,
                default => null,
            },
            'carry_over_max_days_override' =>
                $validated['mode'] === 'allow'
                    ? $validated['maximum_days']
                    : null,
        ])->save();

        return response()->json([
            'data' => [
                'id' => $person->id,
                'mode' => $validated['mode'],
                'maximum_days' =>
                    $person->carry_over_max_days_override,
            ],
            'message' => 'Carry-over rule saved.',
        ]);
    }
}
