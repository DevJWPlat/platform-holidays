<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\LeaveTypeResource;
use App\Models\LeaveType;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class LeaveTypeController extends Controller
{
    public function index(Request $request)
    {
        $types = LeaveType::query()
            ->where('organisation_id', $request->user()->organisation_id)
            ->where('is_active', true)
            ->orderBy('display_order')
            ->orderBy('label')
            ->get();

        return LeaveTypeResource::collection($types);
    }

    public function store(Request $request): JsonResponse
    {
        $viewer = $request->user();
        abort_unless($viewer->isAdministrator(), 403);

        $validated = $this->validated($request);

        $baseKey = Str::slug($validated['label']) ?: 'leave';
        $key = $baseKey;
        $counter = 2;

        while (
            LeaveType::withTrashed()
                ->where('organisation_id', $viewer->organisation_id)
                ->where('key', $key)
                ->exists()
        ) {
            $key = $baseKey . '-' . $counter++;
        }

        $nextOrder = (int) LeaveType::query()
            ->where('organisation_id', $viewer->organisation_id)
            ->max('display_order') + 1;

        $leaveType = LeaveType::query()->create([
            'organisation_id' => $viewer->organisation_id,
            'label' => trim($validated['label']),
            'key' => $key,
            'colour' => $validated['colour'],
            'icon' => $validated['icon'],
            'icon_colour' => $validated['icon_colour'],
            'is_system' => false,
            'is_protected_holiday' => false,
            'visibility' => 'everyone',
            'requires_approval' => (bool) $validated['requires_approval'],
            'allow_half_days' => (bool) $validated['allow_half_days'],
            'include_in_staffing_limits' =>
                (bool) $validated['include_in_staffing_limits'],
            'annual_usage_limit_minutes' => null,
            'external_availability' => 'busy',
            'is_active' => true,
            'display_order' => $nextOrder,
        ]);

        return response()->json([
            'data' => new LeaveTypeResource($leaveType),
        ], 201);
    }

    public function update(Request $request, LeaveType $leaveType): JsonResponse
    {
        $viewer = $request->user();
        abort_unless($viewer->isAdministrator(), 403);
        abort_unless($leaveType->organisation_id === $viewer->organisation_id, 404);

        $validated = $this->validated($request);

        if ($leaveType->isHoliday() && $validated['allow_half_days']) {
            throw ValidationException::withMessages([
                'allow_half_days' => [
                    'Holiday is protected and must remain full days only.',
                ],
            ]);
        }

        $leaveType->update([
            'label' => trim($validated['label']),
            'colour' => $validated['colour'],
            'icon' => $validated['icon'],
            'icon_colour' => $validated['icon_colour'],
            'requires_approval' => (bool) $validated['requires_approval'],
            'allow_half_days' => $leaveType->isHoliday()
                ? false
                : (bool) $validated['allow_half_days'],
            'include_in_staffing_limits' =>
                (bool) $validated['include_in_staffing_limits'],
        ]);

        return response()->json([
            'data' => new LeaveTypeResource($leaveType->fresh()),
        ]);
    }

    public function destroy(Request $request, LeaveType $leaveType): JsonResponse
    {
        $viewer = $request->user();
        abort_unless($viewer->isAdministrator(), 403);
        abort_unless($leaveType->organisation_id === $viewer->organisation_id, 404);

        if ($leaveType->isHoliday() || $leaveType->is_protected_holiday) {
            throw ValidationException::withMessages([
                'leave_type' => ['Holiday is protected and cannot be removed.'],
            ]);
        }

        $hasRequests = $leaveType->leaveRequests()->exists();

        if ($hasRequests) {
            $leaveType->update(['is_active' => false]);
        } else {
            $leaveType->delete();
        }

        return response()->json([
            'message' => $hasRequests
                ? 'Leave type archived.'
                : 'Leave type removed.',
        ]);
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'label' => ['required', 'string', 'max:120'],
            'colour' => [
                'required',
                'string',
                'regex:/^#[0-9A-Fa-f]{6}$/',
            ],
            'icon' => ['required', 'string', 'max:120'],
            'icon_colour' => ['required', 'in:white,black'],
            'requires_approval' => ['required', 'boolean'],
            'allow_half_days' => ['required', 'boolean'],
            'include_in_staffing_limits' => ['required', 'boolean'],
        ]);
    }
}
