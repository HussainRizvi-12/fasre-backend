<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreFacultyAssignmentRequest;
use App\Models\FacultyAssignment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class FacultyAssignmentController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = FacultyAssignment::with(['section.course', 'faculty']);

        if ($request->has('section_id')) {
            $query->where('section_id', $request->section_id);
        }

        if ($request->has('faculty_id')) {
            $query->where('faculty_id', $request->faculty_id);
        }

        return response()->json([
            'data' => $query->get(),
            'message' => 'Faculty assignments retrieved successfully.',
        ]);
    }

    public function store(StoreFacultyAssignmentRequest $request): JsonResponse
    {
        $assignment = DB::transaction(function () use ($request) {
            // If this assignment is primary, unset any existing primary for this section
            if ($request->input('is_primary', true)) {
                FacultyAssignment::where('section_id', $request->section_id)
                    ->where('is_primary', true)
                    ->update(['is_primary' => false]);
            }

            return FacultyAssignment::create([
                'section_id' => $request->section_id,
                'faculty_id' => $request->faculty_id,
                'is_primary' => $request->input('is_primary', true),
            ]);
        });

        return response()->json([
            'data' => $assignment->load(['section.course', 'faculty']),
            'message' => 'Faculty assigned to section successfully.',
        ], 201);
    }

    public function destroy(FacultyAssignment $facultyAssignment): JsonResponse
    {
        $facultyAssignment->delete();

        return response()->json([
            'message' => 'Faculty assignment removed successfully.',
        ]);
    }
}
