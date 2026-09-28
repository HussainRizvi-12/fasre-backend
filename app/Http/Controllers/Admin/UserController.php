<?php

namespace App\Http\Controllers\Admin;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreUserRequest;
use App\Http\Requests\Admin\UpdateUserRequest;
use App\Models\AuditAssignment;
use App\Models\AuditImprovementAction;
use App\Models\ReviewWindowRoster;
use App\Models\User;
use App\Services\ActivityLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class UserController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $caller = $request->user();
        $query = User::query();

        if ($caller && $caller->isAdmin() && ! $caller->isCentralQa()) {
            $query->where('department_id', $caller->department_id)
                ->where('role', '!=', UserRole::Admin);
        }

        if ($request->has('role')) {
            $query->where('role', $request->role);
        }

        if ($request->has('is_active')) {
            $query->where('is_active', filter_var($request->is_active, FILTER_VALIDATE_BOOLEAN));
        }

        if ($request->filled('search')) {
            $term = '%'.$request->input('search').'%';
            $query->where(function ($q) use ($term) {
                $q->where('name', 'like', $term)->orWhere('email', 'like', $term);
            });
        }

        // Backward-compatible: paginated=false (or per_page=all) returns all rows.
        $paginated = $request->query('paginated', 'false') === 'true' || $request->query('paginated') === '1';

        if ($paginated) {
            $perPage = max(1, min((int) $request->query('per_page', '50'), 200));
            $paginator = $query->orderBy('name')->paginate($perPage);

            return response()->json([
                'data' => collect($paginator->items()),
                'meta' => [
                    'current_page' => $paginator->currentPage(),
                    'last_page' => $paginator->lastPage(),
                    'per_page' => $paginator->perPage(),
                    'total' => $paginator->total(),
                ],
                'message' => 'Users retrieved successfully.',
            ]);
        }

        return response()->json([
            'data' => $query->orderBy('name')->get(),
            'message' => 'Users retrieved successfully.',
        ]);
    }

    public function store(StoreUserRequest $request): JsonResponse
    {
        $validated = $request->validated();
        if (! $request->user()->isCentralQa()) {
            $validated['department_id'] = $request->user()->department_id;
        }

        $user = User::create($validated);

        ActivityLogger::log($user, 'user.created', ['name' => $user->name, 'role' => $user->role->value]);

        return response()->json([
            'data' => $user,
            'message' => 'User created successfully.',
        ], 201);
    }

    public function show(Request $request, User $user): JsonResponse
    {
        $caller = $request->user();
        if ($caller && ! $caller->isCentralQa()) {
            if ($user->isAdmin() || (int) $user->department_id !== (int) $caller->department_id) {
                return response()->json(['message' => 'Forbidden. Access restricted by department scope.'], 403);
            }
        }

        return response()->json([
            'data' => $user,
            'message' => 'User retrieved successfully.',
        ]);
    }

    public function update(UpdateUserRequest $request, User $user): JsonResponse
    {
        $validated = $request->validated();
        if ($request->user()->id === $user->id
            && ((array_key_exists('is_active', $validated) && ! $validated['is_active'])
                || (isset($validated['role']) && $validated['role'] !== $user->role->value)
                || (array_key_exists('department_id', $validated) && $validated['department_id'] != $user->department_id))) {
            throw ValidationException::withMessages(['user' => 'You cannot remove your own administrative access. Ask another administrator to make this change.']);
        }
        DB::transaction(function () use ($user, $validated) {
            $user->update($validated);
            if ($user->wasChanged(['password', 'email', 'role', 'department_id', 'is_active'])) {
                $user->tokens()->delete();
                DB::table('mfa_enrollments')->where('user_id', $user->id)->delete();
            }
        });

        ActivityLogger::log($user, 'user.updated', ['name' => $user->name]);

        return response()->json([
            'data' => $user->fresh(),
            'message' => 'User updated successfully.',
        ]);
    }

    public function destroy(Request $request, User $user): JsonResponse
    {
        $caller = $request->user();
        if ($caller && ! $caller->isCentralQa()) {
            if ($user->isAdmin() || (int) $user->department_id !== (int) $caller->department_id) {
                return response()->json(['message' => 'Forbidden. Access restricted by department scope.'], 403);
            }
        }

        if ($caller->id === $user->id) {
            throw ValidationException::withMessages(['user' => 'You cannot delete your own account.']);
        }

        DB::transaction(function () use ($user) {
            $user = User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            $hasAcademicRecords = $user->facultyAssignments()->exists()
                || $user->studentEnrollments()->exists()
                || $user->reviewParticipations()->exists()
                || ReviewWindowRoster::where('student_id', $user->id)->exists()
                || AuditAssignment::where('auditor_id', $user->id)->orWhere('auditee_id', $user->id)->orWhere('assigned_by', $user->id)->exists()
                || AuditImprovementAction::where('owner_id', $user->id)->exists();
            if ($hasAcademicRecords) {
                throw ValidationException::withMessages(['user' => 'This account has academic records. Deactivate it instead to preserve evaluation and audit history.']);
            }
            ActivityLogger::log(null, 'user.deleted', ['name' => $user->name, 'email' => $user->email]);
            $user->tokens()->delete();
            $user->delete();
        });

        return response()->json([
            'message' => 'User deleted successfully.',
        ]);
    }
}
