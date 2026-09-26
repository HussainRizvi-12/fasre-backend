<?php

namespace App\Http\Controllers\Admin;

use App\Enums\FormType;
use App\Enums\ReviewWindowStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreReviewWindowRequest;
use App\Http\Requests\Admin\UpdateReviewWindowRequest;
use App\Jobs\SendReviewWindowNotificationsJob;
use App\Models\AuditProvenanceLog;
use App\Models\FormVersion;
use App\Models\ReviewWindow;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Services\NotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ReviewWindowController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $query = ReviewWindow::with(['department', 'formVersion', 'sections.course']);

        if ($user && $user->isAdmin() && ! $user->isCentralQa()) {
            $query->where('department_id', $user->department_id);
        }

        return response()->json([
            'data' => $query->orderByDesc('starts_at')->get(),
            'message' => 'Review windows retrieved successfully.',
        ]);
    }

    public function store(StoreReviewWindowRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $sectionIds = $validated['section_ids'] ?? null;
        unset($validated['section_ids']);

        if (! $request->user()->isCentralQa()) {
            $validated['department_id'] = $request->user()->department_id;
        }

        // Default to latest published student review form version if not provided
        if (empty($validated['form_version_id'])) {
            $latestVersion = FormVersion::where('form_type', FormType::StudentReview->value)
                ->where('is_published', true)
                ->latest()
                ->first();
            $validated['form_version_id'] = $latestVersion?->id;
        }

        $reviewWindow = ReviewWindow::create([
            ...$validated,
            'status' => ReviewWindowStatus::Draft,
        ]);

        if (! empty($sectionIds)) {
            $reviewWindow->sections()->sync($sectionIds);
        }

        ActivityLogger::log($reviewWindow, 'review_window.created', ['title' => $reviewWindow->title]);
        AuditProvenanceLog::record(
            $reviewWindow,
            'created',
            $request->user(),
            'Review window created in draft status.',
            null,
            ['title' => $reviewWindow->title, 'term' => $reviewWindow->term, 'status' => $reviewWindow->status->value]
        );

        return response()->json([
            'data' => $reviewWindow->fresh(['department', 'formVersion', 'sections']),
            'message' => 'Review window created successfully.',
        ], 201);
    }

    public function show(Request $request, ReviewWindow $reviewWindow): JsonResponse
    {
        if (! $request->user()->canAccessDepartment($reviewWindow->department_id)) {
            abort(403, 'Forbidden. Access restricted by department scope.');
        }

        return response()->json([
            'data' => $reviewWindow->load(['department', 'formVersion', 'sections.course', 'rosterEntries']),
            'message' => 'Review window retrieved successfully.',
        ]);
    }

    public function update(UpdateReviewWindowRequest $request, ReviewWindow $reviewWindow): JsonResponse
    {
        if (! $request->user()->canAccessDepartment($reviewWindow->department_id)) {
            abort(403, 'Forbidden. Access restricted by department scope.');
        }

        if ($reviewWindow->status !== ReviewWindowStatus::Draft) {
            return response()->json([
                'message' => 'Review window can only be edited while in draft status. Current status: ' . $reviewWindow->status->value,
            ], 422);
        }

        $validated = $request->validated();
        $sectionIds = $validated['section_ids'] ?? null;
        unset($validated['section_ids']);

        $beforeState = [
            'title' => $reviewWindow->title,
            'term' => $reviewWindow->term,
            'starts_at' => $reviewWindow->starts_at?->toIso8601String(),
            'ends_at' => $reviewWindow->ends_at?->toIso8601String(),
        ];

        $reviewWindow->update($validated);

        if ($sectionIds !== null) {
            $reviewWindow->sections()->sync($sectionIds);
        }

        ActivityLogger::log($reviewWindow, 'review_window.updated', ['title' => $reviewWindow->title]);
        AuditProvenanceLog::record(
            $reviewWindow,
            'updated',
            $request->user(),
            'Review window updated.',
            $beforeState,
            ['title' => $reviewWindow->title, 'term' => $reviewWindow->term]
        );

        return response()->json([
            'data' => $reviewWindow->fresh(['department', 'formVersion', 'sections']),
            'message' => 'Review window updated successfully.',
        ]);
    }

    /**
     * POST /api/admin/review-windows/{reviewWindow}/activate
     * Transition: draft → active (concurrency-safe single-active-window enforcement).
     */
    public function activate(Request $request, ReviewWindow $reviewWindow): JsonResponse
    {
        if (! $request->user()->canAccessDepartment($reviewWindow->department_id)) {
            abort(403, 'Forbidden. Access restricted by department scope.');
        }

        $activatedWindow = DB::transaction(function () use ($reviewWindow, $request) {
            $window = ReviewWindow::whereKey($reviewWindow->id)->lockForUpdate()->first();

            if (! $window) {
                abort(404, 'Review window not found.');
            }

            if ($window->status !== ReviewWindowStatus::Draft) {
                abort(422, "Cannot activate: review window must be in 'draft' status. Current status: {$window->status->value}.");
            }

            // Concurrency-safe check: lock and verify no other window is currently active
            $hasOtherActive = ReviewWindow::where('status', ReviewWindowStatus::Active)
                ->where('id', '!=', $window->id)
                ->lockForUpdate()
                ->exists();

            if ($hasOtherActive) {
                abort(422, 'Cannot activate review window: another review window is currently active. Close the active window first.');
            }

            // Ensure form version is bound
            if (! $window->form_version_id) {
                $latestVersion = FormVersion::where('form_type', FormType::StudentReview->value)
                    ->where('is_published', true)
                    ->latest()
                    ->first();
                $window->form_version_id = $latestVersion?->id;
            }

            try {
                $window->status = ReviewWindowStatus::Active;
                $window->save();
            } catch (\Illuminate\Database\QueryException $e) {
                abort(422, 'Cannot activate review window: another review window is currently active. Close the active window first.');
            }

            // Snapshot eligible student roster at cycle opening
            $rosterCount = $window->snapshotRoster();

            ActivityLogger::log($window, 'review_window.activated', [
                'title' => $window->title,
                'roster_count' => $rosterCount,
            ]);

            AuditProvenanceLog::record(
                $window,
                'activated',
                $request->user(),
                "Review window activated. Snapshot created with {$rosterCount} eligible student enrollments.",
                ['status' => 'draft'],
                ['status' => 'active', 'roster_count' => $rosterCount]
            );

            return $window;
        });

        // Asynchronously dispatch notification fan-out via database queue
        SendReviewWindowNotificationsJob::dispatch($activatedWindow);

        return response()->json([
            'data' => $activatedWindow->fresh(['department', 'formVersion', 'sections']),
            'message' => 'Review window activated successfully.',
        ]);
    }

    /**
     * POST /api/admin/review-windows/{reviewWindow}/close
     * Transition: active → closed
     */
    public function close(Request $request, ReviewWindow $reviewWindow): JsonResponse
    {
        if (! $request->user()->canAccessDepartment($reviewWindow->department_id)) {
            abort(403, 'Forbidden. Access restricted by department scope.');
        }

        $closedWindow = DB::transaction(function () use ($reviewWindow, $request) {
            $window = ReviewWindow::whereKey($reviewWindow->id)->lockForUpdate()->first();

            if (! $window) {
                abort(404, 'Review window not found.');
            }

            if ($window->status !== ReviewWindowStatus::Active) {
                abort(422, "Cannot close: review window must be in 'active' status. Current status: {$window->status->value}.");
            }

            $window->status = ReviewWindowStatus::Closed;
            $window->save();

            ActivityLogger::log($window, 'review_window.closed', ['title' => $window->title]);
            AuditProvenanceLog::record(
                $window,
                'closed',
                $request->user(),
                'Review window closed. Collection terminated.',
                ['status' => 'active'],
                ['status' => 'closed']
            );

            return $window;
        });

        return response()->json([
            'data' => $closedWindow->fresh(['department', 'formVersion', 'sections']),
            'message' => 'Review window closed successfully.',
        ]);
    }

    /**
     * POST /api/admin/review-windows/{reviewWindow}/publish-results
     * Transition: closed → published
     */
    public function publishResults(Request $request, ReviewWindow $reviewWindow): JsonResponse
    {
        if (! $request->user()->canAccessDepartment($reviewWindow->department_id)) {
            abort(403, 'Forbidden. Access restricted by department scope.');
        }

        $publishedWindow = DB::transaction(function () use ($reviewWindow, $request) {
            $window = ReviewWindow::whereKey($reviewWindow->id)->lockForUpdate()->first();

            if (! $window) {
                abort(404, 'Review window not found.');
            }

            if ($window->status !== ReviewWindowStatus::Closed) {
                abort(422, "Cannot publish results: review window must be in 'closed' status. Current status: {$window->status->value}.");
            }

            $window->status = ReviewWindowStatus::Published;
            $window->published_at = now();
            $window->save();

            ActivityLogger::log($window, 'review_window.published', ['title' => $window->title]);
            AuditProvenanceLog::record(
                $window,
                'published',
                $request->user(),
                'Review window results authorized and published.',
                ['status' => 'closed'],
                ['status' => 'published', 'published_at' => $window->published_at->toIso8601String()]
            );

            return $window;
        });

        // Notify students
        NotificationService::sendMany(
            User::where('role', 'student')->where('is_active', true)->get(),
            'result',
            'Evaluation results published',
            "Aggregated results for '{$publishedWindow->title}' are now available.",
            ['review_window_id' => $publishedWindow->id, 'route' => '/results'],
        );

        return response()->json([
            'data' => $publishedWindow->fresh(['department', 'formVersion', 'sections']),
            'message' => 'Review window results published successfully.',
        ]);
    }
}
