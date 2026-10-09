<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\DepartmentResource;
use App\Models\Department;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class DepartmentController extends Controller
{
    public function index(Request $request)
    {
        $viewer = $request->user();

        $query = Department::query()
            ->where('organisation_id', $viewer->organisation_id)
            ->where('is_active', true)
            ->withCount('users')
            ->orderBy('name');

        if ($viewer->isDepartmentManager() || $viewer->isAdministrator()) {
            $query->with('users');
        }

        return DepartmentResource::collection($query->get());
    }

    public function store(Request $request)
    {
        $viewer = $request->user();
        abort_unless($viewer->isDepartmentManager() || $viewer->isAdministrator(), 403);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'colour' => ['nullable', 'string', 'max:20'],
            'maximum_absent' => ['nullable', 'integer', 'min:1', 'max:999'],
            'member_ids' => ['array'],
            'member_ids.*' => ['integer'],
        ]);

        $baseSlug = Str::slug($validated['name']) ?: 'department';
        $slug = $baseSlug;
        $counter = 2;

        while (Department::query()
            ->where('organisation_id', $viewer->organisation_id)
            ->where('slug', $slug)
            ->exists()) {
            $slug = $baseSlug . '-' . $counter++;
        }

        $department = Department::query()->create([
            'organisation_id' => $viewer->organisation_id,
            'name' => $validated['name'],
            'slug' => $slug,
            'colour' => $validated['colour'] ?? '#777777',
            'maximum_absent' => $validated['maximum_absent'] ?? null,
            'is_active' => true,
        ]);

        $this->syncMembers($viewer, $department, $validated['member_ids'] ?? []);

        return response()->json([
            'data' => new DepartmentResource(
                $department->fresh()->load('users')->loadCount('users'),
            ),
        ], 201);
    }

    public function update(Request $request, Department $department)
    {
        $viewer = $request->user();
        abort_unless($viewer->isDepartmentManager() || $viewer->isAdministrator(), 403);
        abort_unless($department->organisation_id === $viewer->organisation_id, 404);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'colour' => ['nullable', 'string', 'max:20'],
            'maximum_absent' => ['nullable', 'integer', 'min:1', 'max:999'],
            'member_ids' => ['array'],
            'member_ids.*' => ['integer'],
        ]);

        $department->update([
            'name' => $validated['name'],
            'colour' => $validated['colour'] ?? $department->colour,
            'maximum_absent' => $validated['maximum_absent'] ?? null,
        ]);

        $this->syncMembers($viewer, $department, $validated['member_ids'] ?? []);

        return response()->json([
            'data' => new DepartmentResource(
                $department->fresh()->load('users')->loadCount('users'),
            ),
        ]);
    }

    private function syncMembers(User $viewer, Department $department, array $memberIds): void
    {
        $validIds = User::query()
            ->where('organisation_id', $viewer->organisation_id)
            ->whereIn('id', $memberIds)
            ->pluck('id')
            ->all();

        $sync = [];

        foreach ($validIds as $userId) {
            $existing = $department->users()
                ->where('users.id', $userId)
                ->first();

            $sync[$userId] = [
                'is_primary' => (bool) ($existing?->pivot?->is_primary ?? false),
                'is_manager' => (bool) ($existing?->pivot?->is_manager ?? false),
            ];
        }

        $department->users()->sync($sync);
    }


    public function destroy(Request $request, Department $department)
    {
        abort_unless(
            (int) $department->organisation_id === (int) $request->user()->organisation_id,
            404
        );

        abort_unless(
            $request->user()->isAdministrator()
                || $request->user()->isDepartmentManager(),
            403
        );

        $department->users()->detach();
        $department->is_active = false;
        $department->save();

        return response()->json([
            'message' => 'Department deleted.',
        ]);
    }
}
