<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreUserRequest;
use App\Http\Requests\Admin\UpdateUserRequest;
use App\Models\User;
use App\Services\ActivityLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class UserController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = User::query();

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
            $perPage = min((int) $request->query('per_page', '50'), 200);
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
        $user = User::create($request->validated());

        ActivityLogger::log($user, 'user.created', ['name' => $user->name, 'role' => $user->role->value]);

        return response()->json([
            'data' => $user,
            'message' => 'User created successfully.',
        ], 201);
    }

    public function show(User $user): JsonResponse
    {
        return response()->json([
            'data' => $user,
            'message' => 'User retrieved successfully.',
        ]);
    }

    public function update(UpdateUserRequest $request, User $user): JsonResponse
    {
        $user->update($request->validated());

        ActivityLogger::log($user, 'user.updated', ['name' => $user->name]);

        return response()->json([
            'data' => $user->fresh(),
            'message' => 'User updated successfully.',
        ]);
    }

    public function destroy(User $user): JsonResponse
    {
        ActivityLogger::log(null, 'user.deleted', ['name' => $user->name, 'email' => $user->email]);

        $user->delete();

        return response()->json([
            'message' => 'User deleted successfully.',
        ]);
    }
}
