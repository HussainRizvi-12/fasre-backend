<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ActivityLogController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $perPage = min((int) $request->query('per_page', '50'), 200);
        $page = max((int) $request->query('page', '1'), 1);

        $paginator = ActivityLog::with('user')
            ->orderByDesc('id')
            ->paginate($perPage, ['*'], 'page', $page);

        return response()->json([
            'data' => collect($paginator->items())->map(fn (ActivityLog $l) => [
                'id' => $l->id,
                'user_name' => $l->user?->name ?? 'System',
                'action' => $l->action,
                'subject' => $l->subject_type ? class_basename($l->subject_type) : null,
                'subject_id' => $l->subject_id,
                'properties' => $l->properties,
                'created_at' => $l->created_at?->toIso8601String(),
            ])->all(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
            'message' => 'Activity log retrieved successfully.',
        ]);
    }
}
