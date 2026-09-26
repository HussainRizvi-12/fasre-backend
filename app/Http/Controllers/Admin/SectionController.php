<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreSectionRequest;
use App\Http\Requests\Admin\UpdateSectionRequest;
use App\Models\Section;
use Illuminate\Http\JsonResponse;

class SectionController extends Controller
{
    public function index(\Illuminate\Http\Request $request): JsonResponse
    {
        $user = $request->user();
        $query = Section::with('course.department');

        if ($user && $user->isAdmin() && ! $user->isCentralQa()) {
            $query->whereHas('course', fn ($q) => $q->where('department_id', $user->department_id));
        }

        return response()->json([
            'data' => $query->get(),
            'message' => 'Sections retrieved successfully.',
        ]);
    }

    public function store(StoreSectionRequest $request): JsonResponse
    {
        $course = \App\Models\Course::find($request->input('course_id'));
        if (! $request->user()->canAccessDepartment($course?->department_id)) {
            abort(403, 'Forbidden. Access restricted by department scope.');
        }

        $section = Section::create($request->validated());

        return response()->json([
            'data' => $section->load('course.department'),
            'message' => 'Section created successfully.',
        ], 201);
    }

    public function show(\Illuminate\Http\Request $request, Section $section): JsonResponse
    {
        if (! $request->user()->canAccessDepartment($section->course?->department_id)) {
            abort(403, 'Forbidden. Access restricted by department scope.');
        }

        return response()->json([
            'data' => $section->load('course.department'),
            'message' => 'Section retrieved successfully.',
        ]);
    }

    public function update(UpdateSectionRequest $request, Section $section): JsonResponse
    {
        if (! $request->user()->canAccessDepartment($section->course?->department_id)) {
            abort(403, 'Forbidden. Access restricted by department scope.');
        }

        if ($request->filled('course_id')) {
            $newCourse = \App\Models\Course::find($request->input('course_id'));
            if (! $request->user()->canAccessDepartment($newCourse?->department_id)) {
                abort(403, 'Forbidden. Target course is outside your authorized department scope.');
            }
        }

        $section->update($request->validated());

        return response()->json([
            'data' => $section->fresh()->load('course.department'),
            'message' => 'Section updated successfully.',
        ]);
    }

    public function destroy(\Illuminate\Http\Request $request, Section $section): JsonResponse
    {
        if (! $request->user()->canAccessDepartment($section->course?->department_id)) {
            abort(403, 'Forbidden. Access restricted by department scope.');
        }

        $hasHistory = $section->hasEvaluations();

        // Perform soft delete to preserve historical references
        $section->delete();

        \App\Models\AuditProvenanceLog::record(
            $section,
            $hasHistory ? 'archived_with_evaluations' : 'archived',
            $request->user(),
            $hasHistory ? 'Section archived; historical evaluations protected against deletion.' : 'Section archived.',
            ['name' => $section->name, 'term' => $section->term, 'course_id' => $section->course_id]
        );

        return response()->json([
            'message' => $hasHistory
                ? 'Section archived. Historical evaluation and audit records have been preserved.'
                : 'Section deleted successfully.',
        ]);
    }
}
