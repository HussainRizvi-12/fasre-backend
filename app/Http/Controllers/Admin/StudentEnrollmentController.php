<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreStudentEnrollmentRequest;
use App\Models\StudentEnrollment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StudentEnrollmentController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = StudentEnrollment::with(['section.course', 'student']);

        if ($request->has('section_id')) {
            $query->where('section_id', $request->section_id);
        }

        if ($request->has('student_id')) {
            $query->where('student_id', $request->student_id);
        }

        return response()->json([
            'data' => $query->get(),
            'message' => 'Student enrollments retrieved successfully.',
        ]);
    }

    public function store(StoreStudentEnrollmentRequest $request): JsonResponse
    {
        $enrollment = StudentEnrollment::create($request->validated());

        return response()->json([
            'data' => $enrollment->load(['section.course', 'student']),
            'message' => 'Student enrolled in section successfully.',
        ], 201);
    }

    public function destroy(StudentEnrollment $studentEnrollment): JsonResponse
    {
        $studentEnrollment->delete();

        return response()->json([
            'message' => 'Student enrollment removed successfully.',
        ]);
    }
}
