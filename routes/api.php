<?php

use App\Http\Controllers\Admin\ActivityLogController;
use App\Http\Controllers\Admin\AuditAssignmentController;
use App\Http\Controllers\Admin\BulkImportController;
use App\Http\Controllers\Admin\CourseController;
use App\Http\Controllers\Admin\DepartmentController;
use App\Http\Controllers\Admin\ExportController;
use App\Http\Controllers\Admin\FacultyAssignmentController;
use App\Http\Controllers\Admin\QuestionController;
use App\Http\Controllers\Admin\ReviewResultsController;
use App\Http\Controllers\Admin\ReviewWindowController;
use App\Http\Controllers\Admin\SectionController;
use App\Http\Controllers\Admin\StudentEnrollmentController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\Auth\PasswordResetController;
use App\Http\Controllers\Faculty\FacultyAuditController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\Student\StudentReviewController;
use App\Http\Middleware\EnsureUserIsAdmin;
use App\Http\Middleware\EnsureUserIsFaculty;
use App\Http\Middleware\EnsureUserIsStudent;
use Illuminate\Support\Facades\Route;

$registerApiRoutes = function () {
    // ── Auth (Public) ───────────────────────────────────────────────
    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:10,1');
    Route::post('/auth/login', [AuthController::class, 'login'])->middleware('throttle:10,1');
    Route::post('/forgot-password', [PasswordResetController::class, 'forgot'])->middleware('throttle:6,1');
    Route::post('/reset-password', [PasswordResetController::class, 'reset'])->middleware('throttle:6,1');

    // ── Auth (Protected) ────────────────────────────────────────────
    Route::middleware('auth:sanctum')->group(function () {
        Route::post('/logout', [AuthController::class, 'logout']);
        Route::post('/auth/logout', [AuthController::class, 'logout']);
        Route::get('/me', [AuthController::class, 'me']);

        // Notifications (in-app + email; per-user feed)
        Route::get('/notifications', [NotificationController::class, 'index']);
        Route::get('/notifications/unread-count', [NotificationController::class, 'unreadCount']);
        Route::patch('/notifications/read-all', [NotificationController::class, 'markAllRead']);
        Route::patch('/notifications/{id}/read', [NotificationController::class, 'markRead']);
    });

    // ── Admin APIs ──────────────────────────────────────────────────
    Route::prefix('admin')
        ->middleware(['auth:sanctum', EnsureUserIsAdmin::class])
        ->group(function () {

            // Users CRUD
            Route::apiResource('users', UserController::class);

            // Departments CRUD
            Route::apiResource('departments', DepartmentController::class);

            // Courses CRUD
            Route::apiResource('courses', CourseController::class);

            // Sections CRUD
            Route::apiResource('sections', SectionController::class);

            // Faculty Assignments (index, store, destroy only)
            Route::apiResource('faculty-assignments', FacultyAssignmentController::class)
                ->only(['index', 'store', 'destroy']);

            // Student Enrollments (index, store, destroy only)
            Route::apiResource('student-enrollments', StudentEnrollmentController::class)
                ->only(['index', 'store', 'destroy']);

            // Questions CRUD
            Route::apiResource('questions', QuestionController::class)
                ->except(['show']);

            // Review Windows CRUD + State Machine
            Route::apiResource('review-windows', ReviewWindowController::class)
                ->except(['show', 'destroy']);
            Route::post('review-windows/{review_window}/activate', [ReviewWindowController::class, 'activate']);
            Route::post('review-windows/{review_window}/close', [ReviewWindowController::class, 'close']);
            Route::post('review-windows/{review_window}/publish-results', [ReviewWindowController::class, 'publishResults']);

            // Review Results Aggregation (Phase 6)
            Route::get('review-results', [ReviewResultsController::class, 'index']);

            // Audit Assignments (Admin management API for the web portal)
            Route::apiResource('audit-assignments', AuditAssignmentController::class)
                ->only(['index', 'store', 'show']);
            Route::post('audit-assignments/{id}/approve', [AuditAssignmentController::class, 'approve']);
            Route::post('audit-assignments/{id}/reject', [AuditAssignmentController::class, 'reject']);

            // Bulk CSV import (users, courses, sections, enrollments, faculty assignments)
            Route::post('bulk-import', [BulkImportController::class, 'import']);

            // Exports (CSV downloads for archival / reporting)
            Route::get('export/users', [ExportController::class, 'users']);
            Route::get('export/courses', [ExportController::class, 'courses']);
            Route::get('export/sections', [ExportController::class, 'sections']);
            Route::get('export/enrollments', [ExportController::class, 'enrollments']);
            Route::get('export/review-results', [ExportController::class, 'reviewResults']);
            Route::get('export/audit-assignments', [ExportController::class, 'auditAssignments']);
            Route::get('export/activity-logs', [ExportController::class, 'activityLogs']);

            // Admin activity trail (accountability)
            Route::get('activity-logs', [ActivityLogController::class, 'index']);
        });

    // ── Student Review APIs ─────────────────────────────────────────
    Route::prefix('student')
        ->middleware(['auth:sanctum', EnsureUserIsStudent::class])
        ->group(function () {
            Route::get('/enrolled-sections', [StudentReviewController::class, 'enrolledSections']);
            Route::get('/review-windows/active', [StudentReviewController::class, 'activeReviewWindow']);
            Route::get('/review-form', [StudentReviewController::class, 'reviewForm']);
            Route::post('/reviews', [StudentReviewController::class, 'store']);
            Route::get('/review-results/published', [StudentReviewController::class, 'publishedResults']);
        });

    // ── Faculty Audit APIs (Phase 5 & 6) ────────────────────────────
    Route::prefix('faculty')
        ->middleware(['auth:sanctum', EnsureUserIsFaculty::class])
        ->group(function () {
            Route::get('/assigned-audits', [FacultyAuditController::class, 'assignedAudits']);
            Route::get('/audits/{id}', [FacultyAuditController::class, 'show']);
            Route::get('/audit-form', [FacultyAuditController::class, 'auditForm']);
            Route::post('/audits/{id}/save-draft', [FacultyAuditController::class, 'saveDraft']);
            Route::post('/audits/{id}/submit', [FacultyAuditController::class, 'submit']);
            Route::get('/my-submissions', [FacultyAuditController::class, 'mySubmissions']);
            Route::get('/my-reports', [FacultyAuditController::class, 'myReports']);
        });
};

$registerApiRoutes();
Route::prefix('v1')->group($registerApiRoutes);
