<?php

namespace App\Http\Controllers\Admin;

use App\Enums\ReviewWindowStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreReviewWindowRequest;
use App\Http\Requests\Admin\UpdateReviewWindowRequest;
use App\Models\ReviewWindow;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Services\NotificationService;
use Illuminate\Http\JsonResponse;

class ReviewWindowController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json([
            'data' => ReviewWindow::orderByDesc('starts_at')->get(),
            'message' => 'Review windows retrieved successfully.',
        ]);
    }

    public function store(StoreReviewWindowRequest $request): JsonResponse
    {
        $reviewWindow = ReviewWindow::create([
            ...$request->validated(),
            'status' => ReviewWindowStatus::Draft,
        ]);

        return response()->json([
            'data' => $reviewWindow,
            'message' => 'Review window created successfully.',
        ], 201);
    }

    public function update(UpdateReviewWindowRequest $request, ReviewWindow $reviewWindow): JsonResponse
    {
        // Only allow editing while in draft status
        if ($reviewWindow->status !== ReviewWindowStatus::Draft) {
            return response()->json([
                'message' => 'Review window can only be edited while in draft status. Current status: ' . $reviewWindow->status->value,
            ], 422);
        }

        $reviewWindow->update($request->validated());

        return response()->json([
            'data' => $reviewWindow->fresh(),
            'message' => 'Review window updated successfully.',
        ]);
    }

    /**
     * POST /api/admin/review-windows/{reviewWindow}/activate
     * Transition: draft → active (blocks activation if another window is already active)
     */
    public function activate(ReviewWindow $reviewWindow): JsonResponse
    {
        if ($reviewWindow->status !== ReviewWindowStatus::Draft) {
            return response()->json([
                'message' => "Cannot activate: review window must be in 'draft' status. Current status: {$reviewWindow->status->value}.",
            ], 422);
        }

        // Single active window enforcement: block if another window is already active
        $hasOtherActive = ReviewWindow::where('status', ReviewWindowStatus::Active)
            ->where('id', '!=', $reviewWindow->id)
            ->exists();

        if ($hasOtherActive) {
            return response()->json([
                'message' => 'Cannot activate review window: another review window is currently active. Close the active window first.',
            ], 422);
        }

        $reviewWindow->update(['status' => ReviewWindowStatus::Active]);

        ActivityLogger::log($reviewWindow, 'review_window.activated', ['title' => $reviewWindow->title]);

        // Notify every active student that the evaluation cycle is open.
        NotificationService::sendMany(
            User::where('role', 'student')->where('is_active', true)->get(),
            'window',
            'Review window is now open',
            "'{$reviewWindow->title}' is now open. Submit your anonymous course evaluations before it closes on {$reviewWindow->ends_at->toFormattedDateString()}.",
            ['review_window_id' => $reviewWindow->id, 'route' => '/courses'],
        );

        return response()->json([
            'data' => $reviewWindow->fresh(),
            'message' => 'Review window activated successfully.',
        ]);
    }

    /**
     * POST /api/admin/review-windows/{reviewWindow}/close
     * Transition: active → closed
     */
    public function close(ReviewWindow $reviewWindow): JsonResponse
    {
        if ($reviewWindow->status !== ReviewWindowStatus::Active) {
            return response()->json([
                'message' => "Cannot close: review window must be in 'active' status. Current status: {$reviewWindow->status->value}.",
            ], 422);
        }

        $reviewWindow->update(['status' => ReviewWindowStatus::Closed]);

        ActivityLogger::log($reviewWindow, 'review_window.closed', ['title' => $reviewWindow->title]);

        return response()->json([
            'data' => $reviewWindow->fresh(),
            'message' => 'Review window closed successfully.',
        ]);
    }

    /**
     * POST /api/admin/review-windows/{reviewWindow}/publish-results
     * Transition: closed → published
     */
    public function publishResults(ReviewWindow $reviewWindow): JsonResponse
    {
        if ($reviewWindow->status !== ReviewWindowStatus::Closed) {
            return response()->json([
                'message' => "Cannot publish results: review window must be in 'closed' status. Current status: {$reviewWindow->status->value}.",
            ], 422);
        }

        $reviewWindow->update(['status' => ReviewWindowStatus::Published]);

        ActivityLogger::log($reviewWindow, 'review_window.published', ['title' => $reviewWindow->title]);

        // Notify students that aggregated results are now viewable.
        NotificationService::sendMany(
            User::where('role', 'student')->where('is_active', true)->get(),
            'result',
            'Evaluation results published',
            "Aggregated results for '{$reviewWindow->title}' are now available in the Published Results tab.",
            ['review_window_id' => $reviewWindow->id, 'route' => '/results'],
        );

        return response()->json([
            'data' => $reviewWindow->fresh(),
            'message' => 'Review window results published successfully.',
        ]);
    }
}
