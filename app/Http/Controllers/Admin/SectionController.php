<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreSectionRequest;
use App\Http\Requests\Admin\UpdateSectionRequest;
use App\Models\Section;
use Illuminate\Http\JsonResponse;

class SectionController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json([
            'data' => Section::with('course.department')->get(),
            'message' => 'Sections retrieved successfully.',
        ]);
    }

    public function store(StoreSectionRequest $request): JsonResponse
    {
        $section = Section::create($request->validated());

        return response()->json([
            'data' => $section->load('course.department'),
            'message' => 'Section created successfully.',
        ], 201);
    }

    public function show(Section $section): JsonResponse
    {
        return response()->json([
            'data' => $section->load('course.department'),
            'message' => 'Section retrieved successfully.',
        ]);
    }

    public function update(UpdateSectionRequest $request, Section $section): JsonResponse
    {
        $section->update($request->validated());

        return response()->json([
            'data' => $section->fresh()->load('course.department'),
            'message' => 'Section updated successfully.',
        ]);
    }

    public function destroy(Section $section): JsonResponse
    {
        $section->delete();

        return response()->json([
            'message' => 'Section deleted successfully.',
        ]);
    }
}
