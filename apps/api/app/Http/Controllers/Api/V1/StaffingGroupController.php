<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\StaffingGroupResource;
use App\Models\StaffingGroup;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class StaffingGroupController extends Controller
{
    public function index(Request $request)
    {
        return StaffingGroupResource::collection(
            StaffingGroup::query()
                ->where('organisation_id', $request->user()->organisation_id)
                ->where('is_active', true)
                ->with('users')
                ->orderBy('name')
                ->get(),
        );
    }

    public function store(Request $request)
    {
        $viewer = $request->user();
        abort_unless($viewer->isAdministrator(), 403);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'maximum_absent' => ['nullable', 'integer', 'min:1', 'max:999'],
            'member_ids' => ['array'],
            'member_ids.*' => ['integer'],
        ]);

        $baseSlug = Str::slug($validated['name']) ?: 'staffing-group';
        $slug = $baseSlug;
        $counter = 2;

        while (StaffingGroup::query()
            ->where('organisation_id', $viewer->organisation_id)
            ->where('slug', $slug)
            ->exists()) {
            $slug = $baseSlug . '-' . $counter++;
        }

        $group = StaffingGroup::query()->create([
            'organisation_id' => $viewer->organisation_id,
            'name' => $validated['name'],
            'slug' => $slug,
            'maximum_absent' => $validated['maximum_absent'] ?? null,
            'is_active' => true,
        ]);

        $this->syncMembers($viewer, $group, $validated['member_ids'] ?? []);

        return response()->json([
            'data' => new StaffingGroupResource($group->fresh()->load('users')),
        ], 201);
    }

    public function update(Request $request, StaffingGroup $staffingGroup)
    {
        $viewer = $request->user();
        abort_unless($viewer->isAdministrator(), 403);
        abort_unless($staffingGroup->organisation_id === $viewer->organisation_id, 404);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'maximum_absent' => ['nullable', 'integer', 'min:1', 'max:999'],
            'member_ids' => ['array'],
            'member_ids.*' => ['integer'],
        ]);

        $staffingGroup->update([
            'name' => $validated['name'],
            'maximum_absent' => $validated['maximum_absent'] ?? null,
        ]);

        $this->syncMembers($viewer, $staffingGroup, $validated['member_ids'] ?? []);

        return response()->json([
            'data' => new StaffingGroupResource($staffingGroup->fresh()->load('users')),
        ]);
    }

    private function syncMembers(User $viewer, StaffingGroup $group, array $memberIds): void
    {
        $ids = User::query()
            ->where('organisation_id', $viewer->organisation_id)
            ->whereIn('id', $memberIds)
            ->pluck('id')
            ->all();

        $group->users()->sync($ids);
    }
}
