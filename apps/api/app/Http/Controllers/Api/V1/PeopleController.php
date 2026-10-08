<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\PersonResource;
use App\Models\Department;
use App\Models\StaffingGroup;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class PeopleController extends Controller
{
    public function index(Request $request)
    {
        $viewer = $request->user();

        $people = User::query()
            ->where('organisation_id', $viewer->organisation_id)
            ->where('is_archived', false)
            ->with(['departments', 'staffingGroups'])
            ->orderBy('name')
            ->get();

        return PersonResource::collection($people);
    }

    public function store(Request $request): JsonResponse
    {
        $viewer = $request->user();
        abort_unless($viewer->isAdministrator(), 403);

        $validated = $this->validatePerson($request);
        $this->validateAllowedDomain($validated['email']);

        $person = DB::transaction(function () use ($viewer, $validated) {
            $person = new User();

            $person->forceFill([
                'organisation_id' => $viewer->organisation_id,
                'name' => trim($validated['name']),
                'email' => strtolower(trim($validated['email'])),
                'password' => Hash::make(Str::random(64)),
                'job_title' => $validated['job_title'] ?: null,
                'role' => $validated['role'],
                'allowance_unit' => 'days',
                'bank_holiday_division' =>
                    $viewer->organisation?->default_bank_holiday_division
                    ?: 'england-and-wales',
                'employment_start_date' => $validated['employment_start_date'] ?: null,
                'date_of_birth' => $validated['date_of_birth'] ?? null,
                'holiday_allowance_override_days' =>
                    $validated['holiday_allowance_override_days'] ?? null,
                'carry_over_override' =>
                    $validated['carry_over_override'] ?? null,
                'carry_over_max_days_override' =>
                    $validated['carry_over_override'] === true
                        ? ($validated['carry_over_max_days_override'] ?? null)
                        : null,
                'can_override_staffing_limits' =>
                    (bool) ($validated['can_override_staffing_limits'] ?? false),
                'is_archived' => false,
                'archived_at' => null,
            ])->save();

        // PATCH_21A_DOB_UPDATE
        if (array_key_exists('date_of_birth', $validated)) {
            $person->forceFill([
                'date_of_birth' => $validated['date_of_birth'] ?: null,
            ])->save();
        }

            $this->syncDepartments(
                $person,
                $validated['department_ids'] ?? [],
                $validated['primary_department_id'] ?? null,
            );
            $this->syncLeadership(
                $person,
                (bool) ($validated['is_leadership'] ?? false),
            );

            return $person;
        });

        return response()->json([
            'data' => new PersonResource($person->fresh()->load(['departments', 'staffingGroups'])),
        ], 201);
    }

    public function update(Request $request, User $person): JsonResponse
    {
        $viewer = $request->user();

        abort_unless($viewer->isAdministrator(), 403);
        abort_unless($person->organisation_id === $viewer->organisation_id, 404);

        $validated = $this->validatePerson($request, $person);
        $this->validateAllowedDomain($validated['email']);

        DB::transaction(function () use ($viewer, $person, $validated) {
            $person->forceFill([
                'name' => trim($validated['name']),
                'email' => strtolower(trim($validated['email'])),
                'job_title' => $validated['job_title'] ?: null,
                'role' => $validated['role'],
                'employment_start_date' => $validated['employment_start_date'] ?: null,
                'date_of_birth' => $validated['date_of_birth'] ?? null,
                'holiday_allowance_override_days' =>
                    $validated['holiday_allowance_override_days'] ?? null,
                'carry_over_override' =>
                    $validated['carry_over_override'] ?? null,
                'carry_over_max_days_override' =>
                    $validated['carry_over_override'] === true
                        ? ($validated['carry_over_max_days_override'] ?? null)
                        : null,
                'can_override_staffing_limits' =>
                    (bool) ($validated['can_override_staffing_limits'] ?? false),
            ])->save();

            $this->syncDepartments(
                $person,
                $validated['department_ids'] ?? [],
                $validated['primary_department_id'] ?? null,
            );
            $this->syncLeadership(
                $person,
                (bool) ($validated['is_leadership'] ?? false),
            );
        });

        return response()->json([
            'data' => new PersonResource($person->fresh()->load('departments')),
        ]);
    }

    public function archive(Request $request, User $person): JsonResponse
    {
        $viewer = $request->user();

        abort_unless($viewer->isAdministrator(), 403);
        abort_unless($person->organisation_id === $viewer->organisation_id, 404);

        if ($person->id === $viewer->id) {
            throw ValidationException::withMessages([
                'person' => ['You cannot archive your own account.'],
            ]);
        }

        $person->forceFill([
            'is_archived' => true,
            'archived_at' => now(),
        ])->save();

        return response()->json([
            'message' => 'Person archived.',
        ]);
    }

    private function validatePerson(Request $request, ?User $person = null): array
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:160'],
            'email' => [
                'required',
                'email',
                'max:255',
                Rule::unique('users', 'email')->ignore($person?->id),
            ],
            'job_title' => ['nullable', 'string', 'max:160'],
            'role' => [
                'required',
                Rule::in([
                    'employee',
                    'approver',
                    'administrator',
                ]),
            ],
            'employment_start_date' => ['nullable', 'date'],
            'date_of_birth' => ['nullable', 'date'],
            'holiday_allowance_override_days' => ['nullable', 'integer', 'min:0', 'max:365'],
            'carry_over_override' => ['nullable', 'boolean'],
            'carry_over_max_days_override' => ['nullable', 'integer', 'min:0', 'max:365'],
            'is_leadership' => ['sometimes', 'boolean'],
            'department_ids' => ['array'],
            'department_ids.*' => ['integer'],
            'primary_department_id' => ['nullable', 'integer'],
            'can_override_staffing_limits' => ['sometimes', 'boolean'],
        ]);

        if (
            ($validated['carry_over_override'] ?? null) === true
            && ! array_key_exists('carry_over_max_days_override', $validated)
        ) {
            throw ValidationException::withMessages([
                'carry_over_max_days_override' => [
                    'Choose a maximum number of carry-over days for this person.',
                ],
            ]);
        }

        if (
            ($validated['carry_over_override'] ?? null) === true
            && $validated['carry_over_max_days_override'] === null
        ) {
            throw ValidationException::withMessages([
                'carry_over_max_days_override' => [
                    'Choose a maximum number of carry-over days for this person.',
                ],
            ]);
        }

        return $validated;
    }

    private function validateAllowedDomain(string $email): void
    {
        $allowedDomain = config('services.google.allowed_domain');

        if (
            $allowedDomain
            && ! str_ends_with(strtolower($email), '@' . strtolower($allowedDomain))
        ) {
            throw ValidationException::withMessages([
                'email' => ["Use a {$allowedDomain} email address."],
            ]);
        }
    }

    private function syncDepartments(
        User $person,
        array $departmentIds,
        ?int $primaryDepartmentId,
    ): void {
        $validDepartments = Department::query()
            ->where('organisation_id', $person->organisation_id)
            ->whereIn('id', $departmentIds)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        if (
            $primaryDepartmentId
            && ! in_array((int) $primaryDepartmentId, $validDepartments, true)
        ) {
            $primaryDepartmentId = null;
        }

        if (! $primaryDepartmentId && count($validDepartments)) {
            $primaryDepartmentId = $validDepartments[0];
        }

        $existingManagerFlags = $person->departments()
            ->get()
            ->mapWithKeys(fn ($department) => [
                (int) $department->id => (bool) $department->pivot?->is_manager,
            ]);

        $sync = [];

        foreach ($validDepartments as $departmentId) {
            $sync[$departmentId] = [
                'is_primary' => $departmentId === (int) $primaryDepartmentId,
                'is_manager' => (bool) ($existingManagerFlags[$departmentId] ?? false),
            ];
        }

        $person->departments()->sync($sync);
    }
    private function syncLeadership(User $person, bool $isLeadership): void
    {
        $leadership = StaffingGroup::query()
            ->where('organisation_id', $person->organisation_id)
            ->where('slug', 'leadership-cover')
            ->first();

        if (! $leadership) {
            return;
        }

        if ($isLeadership) {
            $leadership->users()->syncWithoutDetaching([$person->id]);
            return;
        }

        $leadership->users()->detach($person->id);
    }
}
