<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreDepartmentRequest;
use App\Http\Requests\Admin\UpdateDepartmentRequest;
use App\Models\Department;
use App\Services\ActivityLogger;
use Illuminate\Http\JsonResponse;

class DepartmentController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json([
            'data' => Department::all(),
            'message' => 'Departments retrieved successfully.',
        ]);
    }

    public function store(StoreDepartmentRequest $request): JsonResponse
    {
        if (! $request->user()->isCentralQa()) {
            abort(403, 'Forbidden. Only central QA administrators may create departments.');
        }

        $department = Department::create($request->validated());

        ActivityLogger::log($department, 'department_created', ['name' => $department->name]);

        return response()->json([
            'data' => $department,
            'message' => 'Department created successfully.',
        ], 201);
    }

    public function show(\Illuminate\Http\Request $request, Department $department): JsonResponse
    {
        if (! $request->user()->canAccessDepartment($department->id)) {
            abort(403, 'Forbidden. Access restricted by department scope.');
        }

        return response()->json([
            'data' => $department,
            'message' => 'Department retrieved successfully.',
        ]);
    }

    public function update(UpdateDepartmentRequest $request, Department $department): JsonResponse
    {
        if (! $request->user()->isCentralQa()) {
            abort(403, 'Forbidden. Only central QA administrators may modify departments.');
        }

        $department->update($request->validated());

        return response()->json([
            'data' => $department->fresh(),
            'message' => 'Department updated successfully.',
        ]);
    }

    public function destroy(\Illuminate\Http\Request $request, Department $department): JsonResponse
    {
        if (! $request->user()->isCentralQa()) {
            abort(403, 'Forbidden. Only central QA administrators may delete departments.');
        }

        $department->delete();

        return response()->json([
            'message' => 'Department deleted successfully.',
        ]);
    }
}
