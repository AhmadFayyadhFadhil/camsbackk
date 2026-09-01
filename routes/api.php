<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\BuildingController;
use App\Http\Controllers\Api\V1\RoomController;
use App\Http\Controllers\Api\V1\UserController;
use App\Http\Controllers\Api\V1\ChecklistItemController;
use App\Http\Controllers\Api\V1\ScheduleController;
use App\Http\Controllers\Api\V1\CsAssignmentController;
use App\Http\Controllers\Api\V1\TaskController;
use App\Http\Controllers\Api\V1\SubmissionController;
use App\Http\Controllers\Api\V1\VerificationController;
use App\Http\Controllers\Api\V1\FindingController;
use App\Http\Controllers\Api\V1\NotificationController;
use App\Http\Controllers\Api\V1\DashboardController;
use App\Http\Controllers\Api\V1\ReportController;
use App\Http\Controllers\Api\V1\FindingCategoryController;
use App\Http\Controllers\Api\V1\SystemSettingController;
use App\Http\Controllers\Api\V1\ShiftController;
use App\Http\Controllers\Api\V1\ChecklistTemplateController;
use App\Http\Controllers\Api\V1\RoomAssetController;
use App\Http\Controllers\Api\V1\CleaningMaterialController;
use App\Http\Controllers\Api\V1\SlaParameterController;
use App\Http\Controllers\Api\V1\AdhocTaskController;
use App\Http\Controllers\Api\V1\RoomAssetAuditController;

Route::prefix('v1')->group(function () {
    // 1. Auth Rute Publik (Dilindungi Rate Limiting Login)
    Route::post('auth/login', [AuthController::class, 'login'])->middleware('throttle:login');
    Route::get('auth/avatar/{userId}', [AuthController::class, 'streamAvatar']);
    Route::get('settings/public', [SystemSettingController::class, 'publicSettings']);
    Route::get('settings/logo/image', [SystemSettingController::class, 'streamLogo']);

    // 2. Rute Terproteksi (Dilindungi Sanctum, CheckTokenExpiry, dan Rate Limiting API Global)
    Route::middleware(['auth:sanctum', 'check.expiry', 'throttle:api'])->group(function () {
        
        // Modul Autentikasi Tambahan
        Route::post('auth/logout', [AuthController::class, 'logout']);
        Route::post('auth/change-password', [AuthController::class, 'changePassword']);
        Route::post('auth/profile', [AuthController::class, 'updateProfile']);
        Route::post('auth/reset-password/{userId}', [AuthController::class, 'resetPassword'])
            ->middleware('role:admin');
        Route::get('auth/me', [AuthController::class, 'me']);

        // Rute Master Data (Terbatas Admin & Supervisor)
        Route::middleware('role:admin,supervisor')->group(function () {
            // Modul Gedung - Write
            Route::post('buildings', [BuildingController::class, 'store']);
            Route::put('buildings/{id}', [BuildingController::class, 'update']);
            Route::patch('buildings/{id}', [BuildingController::class, 'update']);
            Route::delete('buildings/{id}', [BuildingController::class, 'destroy']);
            Route::post('buildings/{id}/shifts', [BuildingController::class, 'assignShifts']);

            // Modul Ruangan - Write
            Route::post('rooms', [RoomController::class, 'store']);
            Route::put('rooms/{id}', [RoomController::class, 'update']);
            Route::patch('rooms/{id}', [RoomController::class, 'update']);
            Route::delete('rooms/{id}', [RoomController::class, 'destroy']);
            Route::get('rooms/{id}/qr-code/download', [RoomController::class, 'downloadQrCode']);

            // Modul Item Checklist - Write
            Route::post('checklist-items', [ChecklistItemController::class, 'store']);
            Route::put('checklist-items/{id}', [ChecklistItemController::class, 'update']);
            Route::patch('checklist-items/{id}', [ChecklistItemController::class, 'update']);
            Route::delete('checklist-items/{id}', [ChecklistItemController::class, 'destroy']);

            // Modul Shift - Write
            Route::post('shifts', [ShiftController::class, 'store']);
            Route::put('shifts/{id}', [ShiftController::class, 'update']);
            Route::patch('shifts/{id}', [ShiftController::class, 'update']);
            Route::delete('shifts/{id}', [ShiftController::class, 'destroy']);

            // Modul Template Checklist - Full CRUD
            Route::apiResource('checklist-templates', ChecklistTemplateController::class);

            // Modul Aset Ruangan - Write
            Route::post('room-assets', [RoomAssetController::class, 'store']);
            Route::put('room-assets/{id}', [RoomAssetController::class, 'update']);
            Route::patch('room-assets/{id}', [RoomAssetController::class, 'update']);
            Route::delete('room-assets/{id}', [RoomAssetController::class, 'destroy']);

            // Modul Bahan Kimia & Alat Kerja - Write
            Route::post('cleaning-materials', [CleaningMaterialController::class, 'store']);
            Route::put('cleaning-materials/{id}', [CleaningMaterialController::class, 'update']);
            Route::patch('cleaning-materials/{id}', [CleaningMaterialController::class, 'update']);
            Route::delete('cleaning-materials/{id}', [CleaningMaterialController::class, 'destroy']);

            // Modul Parameter SLA - Write
            Route::post('sla-parameters', [SlaParameterController::class, 'store']);
            Route::put('sla-parameters/{id}', [SlaParameterController::class, 'update']);
            Route::patch('sla-parameters/{id}', [SlaParameterController::class, 'update']);
            Route::delete('sla-parameters/{id}', [SlaParameterController::class, 'destroy']);

            // Modul Jadwal Kerja - Full CRUD + Fast Aggregated Init Data + Apply Template + Clear All
            Route::get('schedules/init-data', [ScheduleController::class, 'initData']);
            Route::post('schedules/apply-template', [ScheduleController::class, 'applyTemplate']);
            Route::delete('schedules/clear-all', [ScheduleController::class, 'clearAll']);
            Route::apiResource('schedules', ScheduleController::class);

            // Modul Penugasan Kerja CS - Full CRUD
            Route::apiResource('cs-assignments', CsAssignmentController::class);
        });

        // Modul Pengguna (Users) - Read & Write: Admin & Supervisor
        Route::get('users/assignable', [UserController::class, 'assignableUsers'])->middleware('role:admin,supervisor');
        Route::get('users', [UserController::class, 'index'])->middleware('role:admin,supervisor');
        Route::get('users/{id}', [UserController::class, 'show'])->middleware('role:admin,supervisor');
        Route::post('users', [UserController::class, 'store'])->middleware('role:admin,supervisor');
        Route::put('users/{id}', [UserController::class, 'update'])->middleware('role:admin,supervisor');
        Route::patch('users/{id}', [UserController::class, 'update'])->middleware('role:admin,supervisor');
        Route::patch('users/{id}/toggle-status', [UserController::class, 'toggleStatus'])->middleware('role:admin,supervisor');
        Route::delete('users/{id}', [UserController::class, 'destroy'])->middleware('role:admin,supervisor');

        // Rute Read-Only Publik untuk Master Data (Di luar grup di atas, tetap dalam auth:sanctum)
        Route::get('buildings', [BuildingController::class, 'index']);
        Route::get('buildings/{id}', [BuildingController::class, 'show']);
        Route::get('rooms', [RoomController::class, 'index']);
        Route::get('rooms/{id}', [RoomController::class, 'show']);
        Route::get('checklist-items', [ChecklistItemController::class, 'index']);
        Route::get('checklist-items/{id}', [ChecklistItemController::class, 'show']);
        Route::get('checklist-templates', [ChecklistTemplateController::class, 'index']);
        Route::get('checklist-templates/{id}', [ChecklistTemplateController::class, 'show']);
        Route::get('room-assets', [RoomAssetController::class, 'index']);
        Route::get('room-assets/{id}', [RoomAssetController::class, 'show']);
        Route::get('cleaning-materials', [CleaningMaterialController::class, 'index']);
        Route::get('cleaning-materials/{id}', [CleaningMaterialController::class, 'show']);
        Route::get('sla-parameters', [SlaParameterController::class, 'index']);
        Route::get('sla-parameters/{id}', [SlaParameterController::class, 'show']);
        Route::get('shifts', [ShiftController::class, 'index']);
        Route::get('shifts/{id}', [ShiftController::class, 'show']);

        // Modul Audit & Stock Opname Aset Fisik Gedung & Ruangan Berkala
        Route::get('building-asset-audits/summary', [RoomAssetAuditController::class, 'buildingSummary']);
        Route::get('buildings/{id}/assets-tree', [RoomAssetAuditController::class, 'getBuildingAssetsTree']);
        Route::put('buildings/{id}/asset-audit-schedule', [RoomAssetAuditController::class, 'updateBuildingSchedule'])->middleware('role:admin,supervisor');
        Route::get('room-asset-audits/schedule-summary', [RoomAssetAuditController::class, 'scheduleSummary']);
        Route::put('rooms/{id}/asset-audit-schedule', [RoomAssetAuditController::class, 'updateSchedule'])->middleware('role:admin,supervisor');
        Route::get('room-asset-audits', [RoomAssetAuditController::class, 'index']);
        Route::get('room-asset-audits/{id}', [RoomAssetAuditController::class, 'show']);
        Route::post('room-asset-audits', [RoomAssetAuditController::class, 'store']);
        Route::put('room-asset-audits/{id}', [RoomAssetAuditController::class, 'update'])->middleware('role:admin,supervisor');
        Route::delete('room-asset-audits/{id}', [RoomAssetAuditController::class, 'destroy'])->middleware('role:admin,supervisor');
        Route::post('room-asset-audits/{id}/verify', [RoomAssetAuditController::class, 'verify'])->middleware('role:admin,supervisor');
        Route::get('room-asset-audits/{auditId}/items/{itemId}/foto', [RoomAssetAuditController::class, 'streamFoto']);

        // Modul Tugas Khusus, Ad-hoc & Event Terjadwal
        Route::get('adhoc-tasks', [AdhocTaskController::class, 'index']);
        Route::post('adhoc-tasks', [AdhocTaskController::class, 'store'])->middleware('role:admin,supervisor');
        Route::get('adhoc-tasks/{id}', [AdhocTaskController::class, 'show']);
        Route::put('adhoc-tasks/{id}', [AdhocTaskController::class, 'update'])->middleware('role:admin,supervisor');
        Route::delete('adhoc-tasks/{id}', [AdhocTaskController::class, 'destroy'])->middleware('role:admin,supervisor');
        Route::post('adhoc-tasks/{id}/start', [AdhocTaskController::class, 'start']);
        Route::post('adhoc-tasks/{id}/submit', [AdhocTaskController::class, 'submit']);
        Route::post('adhoc-tasks/{id}/submit-setup', [AdhocTaskController::class, 'submitSetup']);
        Route::post('adhoc-tasks/{id}/submit-cleanup', [AdhocTaskController::class, 'submitCleanup']);
        Route::post('adhoc-tasks/{id}/verify', [AdhocTaskController::class, 'verify'])->middleware('role:admin,supervisor');
        Route::get('adhoc-tasks/{id}/foto-bukti', [AdhocTaskController::class, 'streamFotoBukti']);
        Route::get('adhoc-tasks/{id}/foto-persiapan', [AdhocTaskController::class, 'streamFotoPersiapan']);
        Route::get('adhoc-tasks/{id}/foto-cleanup', [AdhocTaskController::class, 'streamFotoCleanup']);

        // Modul Khusus Cleaning Service (Dengan Validasi Shift Kerja & Role CS)
        Route::middleware(['cs.shift', 'role:cs'])->group(function () {
            Route::get('tasks/my-tasks', [TaskController::class, 'myTasks']);
            Route::post('submissions/scan', [SubmissionController::class, 'scanQrCode']);
            Route::post('submissions', [SubmissionController::class, 'store']);
            Route::post('submissions/{id}/resubmit', [SubmissionController::class, 'resubmit']);
        });

        // Modul Tugas Harian (Tasks)
        Route::post('tasks/generate', [TaskController::class, 'generateManual'])->middleware('role:admin,supervisor');
        Route::apiResource('tasks', TaskController::class)->only(['index', 'show']);

        // Modul Penyerahan Laporan (Submissions - Streaming Foto)
        Route::get('submissions/{id}/foto-before', [SubmissionController::class, 'streamFotoBefore']);
        Route::get('submissions/{id}/foto-after-1', [SubmissionController::class, 'streamFotoAfter1']);
        Route::get('submissions/{id}/foto-after-2', [SubmissionController::class, 'streamFotoAfter2']);
        Route::get('submissions/{id}/foto-after-3', [SubmissionController::class, 'streamFotoAfter3']);
        Route::get('submissions/{id}/foto-after-4', [SubmissionController::class, 'streamFotoAfter4']);

        // Modul Verifikasi (Verification)
        Route::get('verifications/pending', [VerificationController::class, 'pending']);
        Route::post('verifications/{submissionId}/approve', [VerificationController::class, 'approve']);
        Route::post('verifications/{submissionId}/reject', [VerificationController::class, 'reject']);
        Route::get('verifications/{id}/foto-inspeksi', [VerificationController::class, 'streamFotoInspeksi']);

        // Modul Temuan Masalah (Findings)
        Route::get('finding-categories', [FindingCategoryController::class, 'index']);
        Route::get('findings', [FindingController::class, 'index']);
        Route::post('findings', [FindingController::class, 'store']);
        Route::get('findings/{id}', [FindingController::class, 'show']);
        Route::get('findings/{id}/foto', [FindingController::class, 'streamFoto']);
        Route::get('findings/{id}/foto-resolved', [FindingController::class, 'streamFotoResolved']);
        Route::get('findings/{id}/foto-ob-1', [FindingController::class, 'streamFotoOb1']);
        Route::get('findings/{id}/foto-ob-2', [FindingController::class, 'streamFotoOb2']);
        Route::get('findings/{id}/foto-ob-3', [FindingController::class, 'streamFotoOb3']);
        Route::get('findings/{id}/foto-ob-4', [FindingController::class, 'streamFotoOb4']);
        Route::patch('findings/{id}/status', [FindingController::class, 'updateStatus'])
            ->middleware('role:admin,supervisor,cs,ob');
        Route::delete('findings/{id}', [FindingController::class, 'destroy'])
            ->middleware('role:admin,pic');

        // Modul Notifikasi (SSE & History)
        Route::get('notifications/stream', [NotificationController::class, 'stream']);
        Route::get('notifications', [NotificationController::class, 'index']);
        Route::patch('notifications/mark-all-read', [NotificationController::class, 'markAllAsRead']);
        Route::delete('notifications/delete-all', [NotificationController::class, 'destroyAll']);
        Route::patch('notifications/{id}/read', [NotificationController::class, 'markAsRead']);
        Route::delete('notifications/{id}', [NotificationController::class, 'destroy']);

        // Modul Dashboard (Analitik per Role)
        Route::get('dashboard/supervisor', [DashboardController::class, 'supervisor'])->middleware('role:admin,supervisor');
        Route::get('dashboard/pic', [DashboardController::class, 'pic'])->middleware('role:pic');
        Route::get('dashboard/cs', [DashboardController::class, 'cs'])->middleware('role:cs');
        Route::get('dashboard/ob', [DashboardController::class, 'ob'])->middleware('role:admin,ob');
        Route::get('dashboard/buildings/{id}', [DashboardController::class, 'buildingDetails']);

        // Modul Laporan & Audit Logs
        Route::post('reports/export/pdf', [ReportController::class, 'exportPdf']);
        Route::post('reports/export/excel', [ReportController::class, 'exportExcel']);
        Route::post('reports/export/findings-pdf', [ReportController::class, 'exportFindingsPdf']);
        Route::post('reports/export/findings-excel', [ReportController::class, 'exportFindingsExcel']);
        Route::get('audit-logs', [ReportController::class, 'auditLogs'])->middleware('role:admin,supervisor,pic,cs');

        // Modul Pengaturan Sistem (System Settings)
        Route::get('settings', [SystemSettingController::class, 'index'])->middleware('role:admin,supervisor');
        Route::put('settings', [SystemSettingController::class, 'update'])->middleware('role:admin,supervisor');
        Route::post('settings/logo', [SystemSettingController::class, 'uploadLogo'])->middleware('role:admin');
    });
});
