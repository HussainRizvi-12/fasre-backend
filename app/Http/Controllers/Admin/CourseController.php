<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreCourseRequest;
use App\Http\Requests\Admin\UpdateCourseRequest;
use App\Models\Course;
use Illuminate\Http\JsonResponse;

class CourseController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json([
            'data' => Course::with('department')->get(),
            'message' => 'Courses retrieved successfully.',
        ]);
    }

    public function store(StoreCourseRequest $request): JsonResponse
    {
        $course = Course::create($request->validated());

        return response()->json([
            'data' => $course->load('department'),
            'message' => 'Course created successfully.',
        ], 201);
    }

    public function show(Course $course): JsonResponse
    {
        return response()->json([
            'data' => $course->load('department'),
            'message' => 'Course retrieved successfully.',
        ]);
    }

    public function update(UpdateCourseRequest $request, Course $course): JsonResponse
    {
        $course->update($request->validated());

        return response()->json([
            'data' => $course->fresh()->load('department'),
            'message' => 'Course updated successfully.',
        ]);
    }

    public function destroy(Course $course): JsonResponse
    {
        $course->delete();

        return response()->json([
            'message' => 'Course deleted successfully.',
        ]);
    }
}
