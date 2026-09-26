<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreCourseRequest;
use App\Http\Requests\Admin\UpdateCourseRequest;
use App\Models\Course;
use Illuminate\Http\JsonResponse;

class CourseController extends Controller
{
    public function index(\Illuminate\Http\Request $request): JsonResponse
    {
        $user = $request->user();
        $query = Course::with('department');

        if ($user && $user->isAdmin() && ! $user->isCentralQa()) {
            $query->where('department_id', $user->department_id);
        }

        return response()->json([
            'data' => $query->get(),
            'message' => 'Courses retrieved successfully.',
        ]);
    }

    public function store(StoreCourseRequest $request): JsonResponse
    {
        if (! $request->user()->canAccessDepartment($request->input('department_id'))) {
            abort(403, 'Forbidden. Access restricted by department scope.');
        }

        $course = Course::create($request->validated());

        return response()->json([
            'data' => $course->load('department'),
            'message' => 'Course created successfully.',
        ], 201);
    }

    public function show(\Illuminate\Http\Request $request, Course $course): JsonResponse
    {
        if (! $request->user()->canAccessDepartment($course->department_id)) {
            abort(403, 'Forbidden. Access restricted by department scope.');
        }

        return response()->json([
            'data' => $course->load('department'),
            'message' => 'Course retrieved successfully.',
        ]);
    }

    public function update(UpdateCourseRequest $request, Course $course): JsonResponse
    {
        if (! $request->user()->canAccessDepartment($course->department_id)) {
            abort(403, 'Forbidden. Access restricted by department scope.');
        }

        if ($request->filled('department_id') && ! $request->user()->canAccessDepartment($request->input('department_id'))) {
            abort(403, 'Forbidden. Target department is outside your authorized department scope.');
        }

        $course->update($request->validated());

        return response()->json([
            'data' => $course->fresh()->load('department'),
            'message' => 'Course updated successfully.',
        ]);
    }

    public function destroy(\Illuminate\Http\Request $request, Course $course): JsonResponse
    {
        if (! $request->user()->canAccessDepartment($course->department_id)) {
            abort(403, 'Forbidden. Access restricted by department scope.');
        }

        $course->delete();

        \App\Models\AuditProvenanceLog::record(
            $course,
            'archived',
            $request->user(),
            'Course archived.',
            ['code' => $course->code, 'title' => $course->title, 'department_id' => $course->department_id]
        );

        return response()->json([
            'message' => 'Course archived successfully.',
        ]);
    }
}
