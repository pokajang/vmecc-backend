<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\PasswordResetController;
use App\Http\Controllers\SocialAuthController;
use App\Http\Controllers\UserManagementController;
use App\Http\Controllers\AuditLogController;
use App\Http\Controllers\TeamController;
use App\Http\Controllers\RosterController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\RolePermissionController;
use App\Http\Controllers\MessageAttachmentController;
use App\Http\Controllers\MessageController;
use App\Http\Controllers\LeaveController;
use App\Http\Controllers\LeaveDraftController;
use App\Http\Controllers\LeaveAttachmentController;
use App\Http\Controllers\LeaveManagementController;
use App\Http\Controllers\LeaveWorkflowController;
use App\Http\Controllers\LeaveAssignmentController;
use App\Http\Controllers\LeaveNotificationController;
use App\Http\Controllers\WorkflowNotificationController;
use App\Http\Controllers\WorkflowAttachmentController;
use App\Http\Controllers\OvertimeController;
use App\Http\Controllers\OvertimeDraftController;
use App\Http\Controllers\OvertimeManagementController;
use App\Http\Controllers\OvertimeWorkflowController;
use App\Http\Controllers\PayrollClaimController;
use App\Http\Controllers\PayrollClaimDraftController;
use App\Http\Controllers\PayrollClaimManagementController;
use App\Http\Controllers\PayrollClaimWorkflowController;
use App\Http\Controllers\PayrollPayslipController;
use App\Http\Controllers\SalaryAssignmentController;
use App\Http\Controllers\SalaryAssignmentDraftController;
use App\Http\Controllers\OtPayrollMigrationController;
use App\Http\Controllers\HolidayController;
use App\Http\Controllers\ErcoReportPdfController;
use App\Http\Controllers\InspectionReportPdfController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\ReportDraftController;
use App\Http\Controllers\DashboardController;
use Illuminate\Support\Facades\Route;

Route::post('auth/login', [AuthController::class, 'login']);
Route::post('auth/logout', [AuthController::class, 'logout']);
Route::get('auth/session', [AuthController::class, 'session']);

Route::get('auth/google/redirect', [SocialAuthController::class, 'redirect']);
Route::get('auth/google/callback', [SocialAuthController::class, 'callback']);

Route::post('password/forgot', [PasswordResetController::class, 'sendResetLink']);
Route::post('password/reset', [PasswordResetController::class, 'reset']);
Route::middleware(['session.auth', 'system.maintenance'])->post('auth/password', [AuthController::class, 'changePassword']);
Route::middleware(['session.auth', 'system.maintenance'])->put('profile', [AuthController::class, 'updateProfile']);
Route::middleware(['session.auth', 'system.maintenance'])->post('profile/image', [AuthController::class, 'uploadProfileImage']);
Route::middleware(['session.auth', 'system.maintenance'])->delete('profile/image', [AuthController::class, 'deleteProfileImage']);
Route::get('settings/system-maintenance', [SettingsController::class, 'getSystemMaintenance']);
Route::middleware(['session.auth', 'system.maintenance'])->get('dashboard/me', [DashboardController::class, 'me'])
    ->middleware('permission.assignment:self.dashboard');
Route::middleware(['session.auth', 'system.maintenance'])->prefix('stats')->group(function () {
    Route::get('payroll', [DashboardController::class, 'payrollStats'])
        ->middleware(['permission.assignment:self.dashboard', 'permission.assignment:dashboard.payroll.view']);
    Route::get('overtime', [DashboardController::class, 'overtimeStats'])
        ->middleware(['permission.assignment:self.dashboard', 'permission.assignment:dashboard.overtime.view']);
    Route::get('leave', [DashboardController::class, 'leaveStats'])
        ->middleware(['permission.assignment:self.dashboard', 'permission.assignment:dashboard.leave.view']);
    Route::get('roster', [DashboardController::class, 'rosterStats'])
        ->middleware(['permission.assignment:self.dashboard', 'permission.assignment:dashboard.roster.view']);
    Route::get('reports', [DashboardController::class, 'reportStats'])
        ->middleware(['permission.assignment:self.dashboard', 'permission.assignment:dashboard.reports.view']);
});
Route::middleware(['session.auth', 'system.maintenance', 'permission.assignment:self.messages'])->group(function () {
    Route::get('messages/contacts', [MessageController::class, 'contacts']);
    Route::get('messages/threads', [MessageController::class, 'threads']);
    Route::get('messages/threads/{userId}', [MessageController::class, 'threadMessages']);
    Route::post('messages/threads/{userId}/read', [MessageController::class, 'markThreadRead']);
    Route::delete('messages/threads/{userId}', [MessageController::class, 'destroyThread']);
    Route::delete('messages/threads/{userId}/everyone', [MessageController::class, 'destroyThreadForEveryone']);
    Route::delete('messages/{id}', [MessageController::class, 'destroy']);
    Route::post('messages/attachments', [MessageAttachmentController::class, 'store']);
    Route::get('messages/attachments/{id}', [MessageAttachmentController::class, 'show']);
    Route::delete('messages/attachments/{id}', [MessageAttachmentController::class, 'destroy']);
    Route::post('messages', [MessageController::class, 'store']);
    Route::get('messages', [MessageController::class, 'inbox']);
    Route::get('messages/sent', [MessageController::class, 'sent']);
    Route::get('messages/{id}', [MessageController::class, 'show']);
    Route::post('messages/{id}/read', [MessageController::class, 'markRead']);
});

    Route::middleware(['session.auth', 'system.maintenance'])->group(function () {
    Route::get('users', [UserManagementController::class, 'index'])
        ->middleware('permission.assignment:users.manage|staff.view|staff.manage|staff.leave.manage|staff.salary.manage|teams.view|teams.manage');
    Route::post('users', [UserManagementController::class, 'store'])->middleware('permission.assignment:users.manage');
    Route::post('users/{id}/status', [UserManagementController::class, 'toggleStatus'])->middleware('permission.assignment:users.manage');
    Route::post('users/{id}/role', [UserManagementController::class, 'updateRole'])->middleware('permission.assignment:roles.assign');
    Route::put('users/{id}/role-assignments', [UserManagementController::class, 'replaceRoleAssignments'])->middleware('permission.assignment:roles.assign');
    Route::post('users/{id}/role-assignments', [UserManagementController::class, 'addRoleAssignments'])->middleware('permission.assignment:roles.assign');
    Route::patch('users/{id}/role-assignments/{assignmentId}', [UserManagementController::class, 'updateRoleAssignment'])->middleware('permission.assignment:roles.assign');
    Route::delete('users/{id}/role-assignments/{assignmentId}', [UserManagementController::class, 'deleteRoleAssignment'])->middleware('permission.assignment:roles.assign');
    Route::post('users/{id}/reset-password', [UserManagementController::class, 'sendResetLink'])->middleware('permission.assignment:users.manage');
    Route::post('users/{id}/lock', [UserManagementController::class, 'lock'])->middleware('permission.assignment:users.manage');
    Route::post('users/{id}/unlock', [UserManagementController::class, 'unlock'])->middleware('permission.assignment:users.manage');
    Route::get('users/{id}/sessions', [UserManagementController::class, 'sessions'])->middleware('permission.assignment:users.manage');
    Route::post('users/{id}/sessions/{sessionId}/revoke', [UserManagementController::class, 'revokeSession'])->middleware('permission.assignment:users.manage');
    Route::post('users/{id}/sessions/revoke-all', [UserManagementController::class, 'revokeAllSessions'])->middleware('permission.assignment:users.manage');
    Route::delete('users/{id}', [UserManagementController::class, 'destroy'])->middleware('permission.assignment:users.manage');
    Route::post('users/{id}/restore', [UserManagementController::class, 'restore'])->middleware('permission.assignment:users.manage');
    Route::get('users/state-quality-report', [UserManagementController::class, 'stateQualityReport'])
        ->middleware('permission.assignment:users.manage');

    Route::get('audit-logs', [AuditLogController::class, 'index'])->middleware('permission.assignment:audit.view');

    Route::get('teams', [TeamController::class, 'index'])->middleware('permission.assignment:teams.view');
    Route::post('teams', [TeamController::class, 'store'])->middleware('permission.assignment:teams.manage');
    Route::get('teams/{team}', [TeamController::class, 'show'])->middleware('permission.assignment.scope:teams.view,team');
    Route::put('teams/{team}', [TeamController::class, 'update'])->middleware('permission.assignment.scope:teams.manage,team');
    Route::post('teams/{team}', [TeamController::class, 'update'])->middleware('permission.assignment.scope:teams.manage,team'); // multipart method-spoofing path
    Route::delete('teams/{team}', [TeamController::class, 'destroy'])->middleware('permission.assignment:teams.manage');
    Route::post('teams/{team}/image', [TeamController::class, 'uploadImage'])->middleware('permission.assignment.scope:teams.manage,team');

    Route::get('rosters', [RosterController::class, 'index'])->middleware('permission.assignment:rosters.manage|teams.view');
    Route::post('rosters', [RosterController::class, 'store'])->middleware('permission.assignment:rosters.manage');
    Route::post('rosters/publish', [RosterController::class, 'publish'])->middleware('permission.assignment:rosters.manage');

    Route::get('settings/role-permissions', [RolePermissionController::class, 'index'])->middleware('permission.assignment:settings.manage');
    Route::put('settings/role-permissions', [RolePermissionController::class, 'update'])->middleware('permission.assignment:settings.manage');

    Route::get('settings/shift-windows', [SettingsController::class, 'getShiftWindows'])->middleware('permission.assignment:settings.manage|staff.leave.manage|staff.salary.manage');
    Route::post('settings/shift-windows', [SettingsController::class, 'updateShiftWindows'])->middleware('permission.assignment:settings.manage|staff.leave.manage|staff.salary.manage');

    Route::get('settings/custom-shifts', [SettingsController::class, 'getCustomShifts'])->middleware('permission.assignment:settings.manage|staff.leave.manage|staff.salary.manage');
    Route::get('settings/all-shifts', [SettingsController::class, 'getAllShifts']);
    Route::post('settings/custom-shifts', [SettingsController::class, 'storeCustomShift'])->middleware('permission.assignment:settings.manage|staff.leave.manage|staff.salary.manage');
    Route::put('settings/custom-shifts/{customShift}', [SettingsController::class, 'updateCustomShift'])->middleware('permission.assignment:settings.manage|staff.leave.manage|staff.salary.manage');
    Route::delete('settings/custom-shifts/{customShift}', [SettingsController::class, 'deleteCustomShift'])->middleware('permission.assignment:settings.manage|staff.leave.manage|staff.salary.manage');
    Route::post('settings/system-maintenance', [SettingsController::class, 'updateSystemMaintenance'])->middleware('permission.assignment:settings.manage');
    Route::get('settings/leave-approval-rules', [SettingsController::class, 'getLeaveApprovalRules'])->middleware('permission.assignment:settings.manage');
    Route::post('settings/leave-approval-rules', [SettingsController::class, 'updateLeaveApprovalRules'])->middleware('permission.assignment:settings.manage');
    Route::get('settings/overtime-approval-rules', [SettingsController::class, 'getOvertimeApprovalRules'])->middleware('permission.assignment:settings.manage');
    Route::post('settings/overtime-approval-rules', [SettingsController::class, 'updateOvertimeApprovalRules'])->middleware('permission.assignment:settings.manage');
    Route::get('settings/overtime-rate-settings', [SettingsController::class, 'getOvertimeRateSettings'])->middleware('permission.assignment:settings.manage');
    Route::post('settings/overtime-rate-settings', [SettingsController::class, 'updateOvertimeRateSettings'])->middleware('permission.assignment:settings.manage');
    Route::get('settings/salary-workflow-rules', [SettingsController::class, 'getSalaryWorkflowRules'])->middleware('permission.assignment:settings.manage');
    Route::post('settings/salary-workflow-rules', [SettingsController::class, 'updateSalaryWorkflowRules'])->middleware('permission.assignment:settings.manage');
    Route::get('settings/salary-statutory-rates', [SettingsController::class, 'getSalaryStatutoryRates'])->middleware('permission.assignment:settings.manage|staff.salary.manage');
    Route::post('settings/salary-statutory-rates', [SettingsController::class, 'updateSalaryStatutoryRates'])->middleware('permission.assignment:settings.manage|staff.salary.manage');
    Route::get('settings/payroll-company-profile', [SettingsController::class, 'getPayrollCompanyProfile'])->middleware('permission.assignment:settings.manage|staff.salary.manage');
    Route::post('settings/payroll-company-profile', [SettingsController::class, 'updatePayrollCompanyProfile'])->middleware('permission.assignment:settings.manage|staff.salary.manage');

    Route::post('workflow/attachments', [WorkflowAttachmentController::class, 'store']);
    Route::get('workflow/attachments/{id}', [WorkflowAttachmentController::class, 'show']);
    Route::delete('workflow/attachments/{id}', [WorkflowAttachmentController::class, 'destroy']);

    Route::get('workflow/notifications', [WorkflowNotificationController::class, 'index']);
    Route::get('workflow/notifications/unread-count', [WorkflowNotificationController::class, 'unreadCount']);
    Route::post('workflow/notifications/read-all', [WorkflowNotificationController::class, 'markAllRead']);
    Route::post('workflow/notifications/{id}/read', [WorkflowNotificationController::class, 'markRead']);
    Route::delete('workflow/notifications', [WorkflowNotificationController::class, 'dismissAll']);
    Route::delete('workflow/notifications/{id}', [WorkflowNotificationController::class, 'dismiss']);

    Route::post('reports/erco/pdf', [ErcoReportPdfController::class, 'download']);
    Route::post('reports/inspection/pdf', [InspectionReportPdfController::class, 'download']);
    Route::get('reports/draft', [ReportDraftController::class, 'show']);
    Route::post('reports/draft', [ReportDraftController::class, 'store']);
    Route::delete('reports/draft', [ReportDraftController::class, 'destroy']);
    Route::get('reports/drafts', [ReportDraftController::class, 'index']);
    Route::post('reports/drafts', [ReportDraftController::class, 'store']);
    Route::get('reports/drafts/{draftId}', [ReportDraftController::class, 'showById']);
    Route::put('reports/drafts/{draftId}', [ReportDraftController::class, 'updateById']);
    Route::delete('reports/drafts/{draftId}', [ReportDraftController::class, 'destroyById']);
    Route::get('reports', [ReportController::class, 'index']);
    Route::post('reports', [ReportController::class, 'store']);
    Route::get('reports/{reportUid}', [ReportController::class, 'show']);
    Route::put('reports/{reportUid}', [ReportController::class, 'update']);
    Route::delete('reports/{reportUid}', [ReportController::class, 'destroy']);
    Route::post('reports/{reportUid}/review', [ReportController::class, 'review']);
    Route::post('reports/{reportUid}/approve', [ReportController::class, 'approve']);
    Route::post('reports/{reportUid}/reject', [ReportController::class, 'reject']);

    Route::post('migration/ot-payroll/import', [OtPayrollMigrationController::class, 'import']);
    Route::get('overtime/eligibility', [OvertimeController::class, 'eligibility'])
        ->middleware('permission.assignment:self.overtime|self.payroll');
    Route::get('overtime', [OvertimeController::class, 'index'])
        ->middleware('permission.assignment:self.overtime|self.payroll');

    // Leave (employee - own records)
    Route::middleware('permission.assignment:self.leave')->group(function () {
        Route::get('leave/draft', [LeaveDraftController::class, 'show']);
        Route::post('leave/draft', [LeaveDraftController::class, 'store']);
        Route::delete('leave/draft', [LeaveDraftController::class, 'destroy']);

        Route::post('leave/attachments', [LeaveAttachmentController::class, 'store']);
        Route::get('leave/attachments/{attachmentId}', [LeaveAttachmentController::class, 'show']);
        Route::delete('leave/attachments/{attachmentId}', [LeaveAttachmentController::class, 'destroy']);

        Route::get('leave/balance', [LeaveAssignmentController::class, 'indexForUser']);
        Route::get('leave/compute-days', [LeaveController::class, 'computeDays']);

        Route::get('leave/notifications', [LeaveNotificationController::class, 'index']);
        Route::get('leave/notifications/unread-count', [LeaveNotificationController::class, 'unreadCount']);
        Route::post('leave/notifications/read-all', [LeaveNotificationController::class, 'markAllRead']);
        Route::post('leave/notifications/{id}/read', [LeaveNotificationController::class, 'markRead']);

        Route::get('leave', [LeaveController::class, 'index']);
        Route::post('leave', [LeaveController::class, 'store']);
        Route::get('leave/{id}', [LeaveController::class, 'show']);
        Route::put('leave/{id}', [LeaveController::class, 'update']);
        Route::delete('leave/{id}', [LeaveController::class, 'destroy']);
        Route::post('leave/{id}/cancel', [LeaveController::class, 'cancel']);
    });

    // Overtime (employee - own records)
    Route::middleware('permission.assignment:self.overtime')->group(function () {
        Route::get('overtime/draft', [OvertimeDraftController::class, 'show']);
        Route::post('overtime/draft', [OvertimeDraftController::class, 'store']);
        Route::delete('overtime/draft', [OvertimeDraftController::class, 'destroy']);
        Route::get('overtime/policy', [OvertimeController::class, 'policy']);
        Route::get('overtime/classify-date', [OvertimeController::class, 'classifyDate']);

        Route::post('overtime', [OvertimeController::class, 'store']);
        Route::get('overtime/{id}', [OvertimeController::class, 'show']);
        Route::put('overtime/{id}', [OvertimeController::class, 'update']);
        Route::delete('overtime/{id}', [OvertimeController::class, 'destroy']);
        Route::post('overtime/{id}/cancel', [OvertimeController::class, 'cancel']);
    });

    // Payroll claims (employee - own records)
    Route::middleware('permission.assignment:self.payroll')->group(function () {
        Route::get('payroll/claims/drafts', [PayrollClaimDraftController::class, 'index']);
        Route::post('payroll/claims/drafts', [PayrollClaimDraftController::class, 'store']);
        Route::delete('payroll/claims/drafts/{id}', [PayrollClaimDraftController::class, 'destroy']);

        Route::get('payroll/claims', [PayrollClaimController::class, 'index']);
        Route::post('payroll/claims', [PayrollClaimController::class, 'store']);
        Route::get('payroll/claims/{id}', [PayrollClaimController::class, 'show']);
        Route::put('payroll/claims/{id}', [PayrollClaimController::class, 'update']);
        Route::delete('payroll/claims/{id}', [PayrollClaimController::class, 'destroy']);
        Route::post('payroll/claims/{id}/cancel', [PayrollClaimController::class, 'cancel']);

        Route::get('payroll/payslips', [PayrollPayslipController::class, 'index']);
        Route::get('payroll/payslips/{id}/download', [PayrollPayslipController::class, 'download']);
    });

    // Holidays (global, HR-managed, visible to all authenticated users)
    Route::get('holidays', [HolidayController::class, 'index']);

    Route::middleware('permission.assignment:staff.leave.manage')->group(function () {
        Route::post('holidays/batch', [HolidayController::class, 'batch']);
        Route::patch('holidays/{id}', [HolidayController::class, 'update']);
        Route::delete('holidays/{id}', [HolidayController::class, 'destroy']);
    });

    // Staff leave management
    Route::get('staff/leave/records', [LeaveManagementController::class, 'index'])
        ->middleware('permission.assignment:staff.leave.manage');
    Route::get('staff/leave/records/{userId}/{leaveId}', [LeaveManagementController::class, 'show'])
        ->middleware('permission.assignment:staff.leave.manage');

    Route::post('staff/leave/records/{userId}/{leaveId}/review', [LeaveWorkflowController::class, 'review'])
        ->middleware('permission.assignment:staff.leave.manage');
    Route::post('staff/leave/records/{userId}/{leaveId}/recommend', [LeaveWorkflowController::class, 'recommend'])
        ->middleware('permission.assignment:staff.leave.manage');
    Route::post('staff/leave/records/{userId}/{leaveId}/approve', [LeaveWorkflowController::class, 'approve'])
        ->middleware('permission.assignment:staff.leave.manage');
    Route::post('staff/leave/records/{userId}/{leaveId}/reject', [LeaveWorkflowController::class, 'reject'])
        ->middleware('permission.assignment:staff.leave.manage');
    Route::post('staff/leave/records/{userId}/{leaveId}/cancel', [LeaveWorkflowController::class, 'adminCancel'])
        ->middleware('permission.assignment:staff.leave.manage');

    Route::get('staff/leave/assignments', [LeaveAssignmentController::class, 'index'])
        ->middleware('permission.assignment:staff.leave.manage');
    Route::post('staff/leave/assignments', [LeaveAssignmentController::class, 'store'])
        ->middleware('permission.assignment:staff.leave.manage');
    Route::put('staff/leave/assignments/{id}', [LeaveAssignmentController::class, 'update'])
        ->middleware('permission.assignment:staff.leave.manage');
    Route::delete('staff/leave/assignments/{id}', [LeaveAssignmentController::class, 'destroy'])
        ->middleware('permission.assignment:staff.leave.manage');

    // Staff overtime management
    Route::get('staff/overtime/records', [OvertimeManagementController::class, 'index'])
        ->middleware('permission.assignment:staff.salary.manage');
    Route::get('staff/overtime/records/{userId}/{recordId}', [OvertimeManagementController::class, 'show'])
        ->middleware('permission.assignment:staff.salary.manage');

    Route::post('staff/overtime/records/{userId}/{recordId}/review', [OvertimeWorkflowController::class, 'review'])
        ->middleware('permission.assignment:staff.salary.manage');
    Route::post('staff/overtime/records/{userId}/{recordId}/recommend', [OvertimeWorkflowController::class, 'recommend'])
        ->middleware('permission.assignment:staff.salary.manage');
    Route::post('staff/overtime/records/{userId}/{recordId}/approve', [OvertimeWorkflowController::class, 'approve'])
        ->middleware('permission.assignment:staff.salary.manage');
    Route::post('staff/overtime/records/{userId}/{recordId}/reject', [OvertimeWorkflowController::class, 'reject'])
        ->middleware('permission.assignment:staff.salary.manage');
    Route::post('staff/overtime/records/{userId}/{recordId}/cancel', [OvertimeWorkflowController::class, 'cancel'])
        ->middleware('permission.assignment:staff.salary.manage');

    // Staff payroll claims management
    Route::get('staff/salary-claims/records', [PayrollClaimManagementController::class, 'index'])
        ->middleware('permission.assignment:staff.salary.manage');
    Route::get('staff/salary-claims/records/{userId}/{claimId}', [PayrollClaimManagementController::class, 'show'])
        ->middleware('permission.assignment:staff.salary.manage');

    Route::post('staff/salary-claims/records/{userId}/{claimId}/check', [PayrollClaimWorkflowController::class, 'check'])
        ->middleware('permission.assignment:staff.salary.manage');
    Route::post('staff/salary-claims/records/{userId}/{claimId}/review', [PayrollClaimWorkflowController::class, 'review'])
        ->middleware('permission.assignment:staff.salary.manage');
    Route::post('staff/salary-claims/records/{userId}/{claimId}/approve', [PayrollClaimWorkflowController::class, 'approve'])
        ->middleware('permission.assignment:staff.salary.manage');
    Route::post('staff/salary-claims/records/{userId}/{claimId}/reject', [PayrollClaimWorkflowController::class, 'reject'])
        ->middleware('permission.assignment:staff.salary.manage');
    Route::post('staff/salary-claims/records/{userId}/{claimId}/cancel', [PayrollClaimWorkflowController::class, 'cancel'])
        ->middleware('permission.assignment:staff.salary.manage');
    Route::post('staff/salary-claims/records/{userId}/{claimId}/mark-paid', [PayrollClaimWorkflowController::class, 'markPaid'])
        ->middleware('permission.assignment:staff.salary.pay');
    Route::post('staff/salary-claims/records/{userId}/{claimId}/unmark-paid', [PayrollClaimWorkflowController::class, 'unmarkPaid'])
        ->middleware('permission.assignment:staff.salary.pay');
    Route::post('staff/salary-claims/records/mark-paid/bulk', [PayrollClaimWorkflowController::class, 'markPaidBulk'])
        ->middleware('permission.assignment:staff.salary.pay');
    Route::post('staff/salary-claims/records/unmark-paid/bulk', [PayrollClaimWorkflowController::class, 'unmarkPaidBulk'])
        ->middleware('permission.assignment:staff.salary.pay');

    // Staff salary assignment management
    Route::get('staff/salary-assignments', [SalaryAssignmentController::class, 'index'])
        ->middleware('permission.assignment:staff.salary.manage');
    Route::post('staff/salary-assignments', [SalaryAssignmentController::class, 'store'])
        ->middleware('permission.assignment:staff.salary.manage');
    Route::put('staff/salary-assignments/{id}', [SalaryAssignmentController::class, 'update'])
        ->middleware('permission.assignment:staff.salary.manage');
    Route::delete('staff/salary-assignments/{id}', [SalaryAssignmentController::class, 'destroy'])
        ->middleware('permission.assignment:staff.salary.manage');
    Route::get('staff/salary-assignments/history', [SalaryAssignmentController::class, 'history'])
        ->middleware('permission.assignment:staff.salary.manage');

    Route::get('staff/salary-assignments/drafts', [SalaryAssignmentDraftController::class, 'index'])
        ->middleware('permission.assignment:staff.salary.manage');
    Route::post('staff/salary-assignments/drafts', [SalaryAssignmentDraftController::class, 'store'])
        ->middleware('permission.assignment:staff.salary.manage');
    Route::put('staff/salary-assignments/drafts/{id}', [SalaryAssignmentDraftController::class, 'update'])
        ->middleware('permission.assignment:staff.salary.manage');
    Route::delete('staff/salary-assignments/drafts/{id}', [SalaryAssignmentDraftController::class, 'destroy'])
        ->middleware('permission.assignment:staff.salary.manage');
});
