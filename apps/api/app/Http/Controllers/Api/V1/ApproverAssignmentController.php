<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\ApproverAssignmentResource;
use App\Models\ApproverAssignment;
use App\Models\Department;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ApproverAssignmentController extends Controller
{
    public function index(Request $request)
    {
        $viewer = $request->user();

        abort_unless(
            $viewer->isDepartmentManager() || $viewer->isAdministrator(),
            403,
        );

        return ApproverAssignmentResource::collection(
            ApproverAssignment::query()
                ->where('organisation_id', $viewer->organisation_id)
                ->with(['employee', 'department', 'approver'])
                ->orderBy('priority')
                ->orderBy('id')
                ->get(),
        );
    }

    public function store(Request $request): JsonResponse
    {
        $viewer = $request->user();
        abort_unless($viewer->isAdministrator(), 403);

        $validated = $this->validateAssignment($request);

        $employee = null;
        $department = null;

        if ($validated['scope_type'] === 'person') {
            $employee = User::query()
                ->where('organisation_id', $viewer->organisation_id)
                ->where('is_archived', false)
                ->findOrFail($validated['employee_id']);
        } else {
            $department = Department::query()
                ->where('organisation_id', $viewer->organisation_id)
                ->where('is_active', true)
                ->findOrFail($validated['department_id']);
        }

        $approver = User::query()
            ->where('organisation_id', $viewer->organisation_id)
            ->where('is_archived', false)
            ->findOrFail($validated['approver_id']);

        if ($employee && $employee->id === $approver->id) {
            throw ValidationException::withMessages([
                'approver_id' => ['A person cannot be their own approver.'],
            ]);
        }

        if ($approver->role === 'employee') {
            $approver->forceFill(['role' => 'approver'])->save();
        }

        $assignment = ApproverAssignment::query()->create([
            'organisation_id' => $viewer->organisation_id,
            'employee_id' => $employee?->id,
            'department_id' => $department?->id,
            'approver_id' => $approver->id,
            'priority' => $validated['priority'],
            'effective_from' => $validated['effective_from'] ?: null,
            'effective_until' => $validated['effective_until'] ?: null,
        ]);

        return response()->json([
            'data' => new ApproverAssignmentResource(
                $assignment->load(['employee', 'department', 'approver']),
            ),
        ], 201);
    }

    public function update(
        Request $request,
        ApproverAssignment $approverAssignment,
    ): JsonResponse {
        $viewer = $request->user();
        abort_unless($viewer->isAdministrator(), 403);
        abort_unless(
            $approverAssignment->organisation_id === $viewer->organisation_id,
            404,
        );

        $validated = $this->validateAssignment($request);

        $employee = null;
        $department = null;

        if ($validated['scope_type'] === 'person') {
            $employee = User::query()
                ->where('organisation_id', $viewer->organisation_id)
                ->where('is_archived', false)
                ->findOrFail($validated['employee_id']);
        } else {
            $department = Department::query()
                ->where('organisation_id', $viewer->organisation_id)
                ->where('is_active', true)
                ->findOrFail($validated['department_id']);
        }

        $approver = User::query()
            ->where('organisation_id', $viewer->organisation_id)
            ->where('is_archived', false)
            ->findOrFail($validated['approver_id']);

        if ($employee && $employee->id === $approver->id) {
            throw ValidationException::withMessages([
                'approver_id' => ['A person cannot be their own approver.'],
            ]);
        }

        if ($approver->role === 'employee') {
            $approver->forceFill(['role' => 'approver'])->save();
        }

        $approverAssignment->update([
            'employee_id' => $employee?->id,
            'department_id' => $department?->id,
            'approver_id' => $approver->id,
            'priority' => $validated['priority'],
            'effective_from' => $validated['effective_from'] ?: null,
            'effective_until' => $validated['effective_until'] ?: null,
        ]);

        return response()->json([
            'data' => new ApproverAssignmentResource(
                $approverAssignment->fresh()->load([
                    'employee',
                    'department',
                    'approver',
                ]),
            ),
        ]);
    }

    public function destroy(
        Request $request,
        ApproverAssignment $approverAssignment,
    ): JsonResponse {
        $viewer = $request->user();
        abort_unless($viewer->isAdministrator(), 403);
        abort_unless(
            $approverAssignment->organisation_id === $viewer->organisation_id,
            404,
        );

        $approverAssignment->delete();

        return response()->json([
            'message' => 'Approval rule removed.',
        ]);
    }

    private function validateAssignment(Request $request): array
    {
        return $request->validate([
            'scope_type' => ['required', Rule::in(['person', 'department'])],
            'employee_id' => [
                Rule::requiredIf(fn () => $request->input('scope_type') === 'person'),
                'nullable',
                'integer',
            ],
            'department_id' => [
                Rule::requiredIf(fn () => $request->input('scope_type') === 'department'),
                'nullable',
                'integer',
            ],
            'approver_id' => ['required', 'integer'],
            'priority' => ['required', 'integer', 'min:1', 'max:999'],
            'effective_from' => ['nullable', 'date'],
            'effective_until' => [
                'nullable',
                'date',
                'after_or_equal:effective_from',
            ],
        ]);
    }
}
