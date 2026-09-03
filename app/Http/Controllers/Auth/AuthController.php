<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class AuthController extends Controller
{
    /**
     * POST /api/login
     * Validate credentials, return Sanctum token + user.
     */
    public function login(LoginRequest $request): JsonResponse
    {
        $user = User::where('email', $request->email)->first();

        if (! $user) {
            // Constant-time dummy hash check to prevent email enumeration timing attacks
            Hash::check($request->password, '$2y$12$eA3VzZ6Xn8bQ9tC2Vw0jJe47dK9a8s7d6f5g4h3j2k1l0m9n8b7v6');

            return response()->json([
                'message' => 'Invalid email or password.',
            ], 401);
        }

        if (! Hash::check($request->password, $user->password)) {
            return response()->json([
                'message' => 'Invalid email or password.',
            ], 401);
        }

        if (! $user->is_active) {
            return response()->json([
                'message' => 'Your account has been deactivated. Please contact the administrator.',
            ], 401);
        }

        $token = $user->createToken('auth-token')->plainTextToken;

        return response()->json([
            'token' => $token,
            'access_token' => $token,
            'user' => $user,
            'data' => [
                'user' => $user,
                'token' => $token,
            ],
            'message' => 'Login successful.',
        ]);
    }

    /**
     * POST /api/logout
     * Revoke the current token.
     */
    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json([
            'message' => 'Logged out successfully.',
        ]);
    }

    /**
     * GET /api/me
     * Return the authenticated user's profile.
     */
    public function me(Request $request): JsonResponse
    {
        return response()->json([
            'user' => $request->user(),
            'data' => $request->user(),
            'message' => 'Authenticated user retrieved.',
        ]);
    }
}
