<?php

use App\Http\Controllers\Api\V1\CarryOverRuleController;

use App\Http\Controllers\Api\V1\CompanyClosureController;

use App\Http\Controllers\Api\V1\WallchartAllowanceController;

use App\Http\Controllers\Api\V1\BankHolidayController;

use App\Http\Controllers\Api\V1\OrganisationSettingsController;
use App\Http\Controllers\Api\V1\NotificationSettingsController;
use App\Http\Controllers\Api\V1\AllowanceController;
use App\Http\Controllers\Api\V1\AllowanceAdjustmentController;
use App\Http\Controllers\Api\V1\HolidayPolicyController;
use App\Http\Controllers\Api\V1\ApproverAssignmentController;
use App\Http\Controllers\Api\V1\DepartmentController;
use App\Http\Controllers\Api\V1\LeaveRequestController;
use App\Http\Controllers\Api\V1\ManualLeaveController;
use App\Http\Controllers\Api\V1\LeaveTypeController;
use App\Http\Controllers\Api\V1\PeopleController;
use App\Http\Controllers\Api\V1\ReportsController;
use App\Http\Controllers\Api\V1\StaffingGroupController;
use App\Http\Controllers\Api\V1\WorkScheduleController;
use App\Http\Controllers\Api\V1\WallchartController;
use App\Http\Controllers\Auth\GoogleAuthController;
use App\Http\Resources\CurrentUserResource;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return redirect(rtrim(config('app.frontend_url', 'http://localhost:5173'), '/') . '/');
});

Route::get('/auth/google/redirect', [GoogleAuthController::class, 'redirect'])
    ->name('google.redirect');

Route::get('/auth/google/callback', [GoogleAuthController::class, 'callback'])
    ->name('google.callback');

Route::middleware('auth')->group(function () {
    Route::prefix('api/v1')->group(function () {
        Route::get('/company-closures', [CompanyClosureController::class, 'index']);
        Route::post('/company-closures', [CompanyClosureController::class, 'store']);
        Route::delete('/company-closures/{closureId}', [CompanyClosureController::class, 'destroy']);
        Route::get('/wallchart/allowances', [WallchartAllowanceController::class, 'index']);
        Route::get('/bank-holidays', [BankHolidayController::class, 'index']);
        Route::get('/organisation-settings', [OrganisationSettingsController::class, 'show']);
        Route::put('/organisation-settings', [OrganisationSettingsController::class, 'update']);
        Route::get('/me', function (Request $request) {
            $user = Auth::user();

            return response()->json([
                'data' => new CurrentUserResource(
                    $user->load(['organisation', 'departments'])
                ),
            ]);
        });

        Route::get('/people', [PeopleController::class, 'index']);
        Route::get('/approver-assignments', [ApproverAssignmentController::class, 'index']);
        Route::post('/approver-assignments', [ApproverAssignmentController::class, 'store']);
        Route::put('/approver-assignments/{approverAssignment}', [ApproverAssignmentController::class, 'update']);
        Route::delete('/approver-assignments/{approverAssignment}', [ApproverAssignmentController::class, 'destroy']);
        Route::post('/people', [PeopleController::class, 'store']);
        Route::put('/people/{person}', [PeopleController::class, 'update']);
        Route::post('/people/{person}/archive', [PeopleController::class, 'archive']);
        Route::get('/departments', [DepartmentController::class, 'index']);
        Route::post('/departments', [DepartmentController::class, 'store']);
        Route::put('/departments/{department}', [DepartmentController::class, 'update']);
        Route::delete('/departments/{department}', [DepartmentController::class, 'destroy']);
        Route::get('/leave-types', [LeaveTypeController::class, 'index']);
        Route::post('/leave-types', [LeaveTypeController::class, 'store']);
        Route::put('/leave-types/{leaveType}', [LeaveTypeController::class, 'update']);
        Route::delete('/leave-types/{leaveType}', [LeaveTypeController::class, 'destroy']);
        Route::get('/work-schedules', [WorkScheduleController::class, 'index']);
        Route::get('/staffing-groups', [StaffingGroupController::class, 'index']);
        Route::post('/staffing-groups', [StaffingGroupController::class, 'store']);
        Route::put('/staffing-groups/{staffingGroup}', [StaffingGroupController::class, 'update']);
        Route::get('/wallchart/leave', [WallchartController::class, 'leave']);
        Route::get('/allowance', [AllowanceController::class, 'show']);
        Route::get('/holiday-policy', [HolidayPolicyController::class, 'show']);
        Route::get('/notification-settings', [NotificationSettingsController::class, 'show']);
        Route::put('/notification-settings', [NotificationSettingsController::class, 'update']);
        Route::put('/holiday-policy', [HolidayPolicyController::class, 'update']);
        Route::get('/carry-over-rules', [CarryOverRuleController::class, 'index']);
        Route::put('/carry-over-rules/{person}', [CarryOverRuleController::class, 'update']);
        Route::get('/people/{person}/allowance', [AllowanceAdjustmentController::class, 'show']);
        Route::post('/people/{person}/allowance/adjustments', [AllowanceAdjustmentController::class, 'store']);
        Route::get('/reports', [ReportsController::class, 'index']);
        Route::get('/leave-requests', [LeaveRequestController::class, 'index']);
        Route::post('/leave-requests/manual/preview', [ManualLeaveController::class, 'preview']);
        Route::post('/leave-requests/manual', [ManualLeaveController::class, 'store']);
        Route::post('/leave-requests/preview', [LeaveRequestController::class, 'preview']);
        Route::post('/leave-requests', [LeaveRequestController::class, 'store']);
        Route::get('/leave-requests/pending', [LeaveRequestController::class, 'pending']);
        Route::post('/leave-requests/{leaveRequest}/approve', [LeaveRequestController::class, 'approve']);
        Route::post('/leave-requests/{leaveRequest}/reject', [LeaveRequestController::class, 'reject']);
        Route::post('/leave-requests/{leaveRequest}/cancel', [LeaveRequestController::class, 'cancel']);
    });

    Route::post('/logout', function (Request $request) {
        Auth::guard('web')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json([
            'message' => 'Signed out.',
        ]);
    });
});
