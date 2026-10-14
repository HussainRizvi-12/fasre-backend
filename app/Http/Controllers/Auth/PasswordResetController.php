<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\Rules\Password as PasswordRule;

class PasswordResetController extends Controller
{
    /**
     * POST /api/forgot-password
     * Sends a password reset link to the user's email (if it exists).
     * Always returns a generic response to avoid account enumeration.
     */
    public function forgot(Request $request): JsonResponse
    {
        $request->validate([
            'email' => ['required', 'email'],
        ]);

        Password::sendResetLink($request->only('email'));

        return response()->json([
            'message' => 'If that email address exists in our records, a password reset link has been sent.',
        ]);
    }

    /**
     * POST /api/reset-password
     * Completes the reset using the token from the email link.
     */
    public function reset(Request $request): JsonResponse
    {
        $request->validate([
            'token' => ['required', 'string'],
            'email' => ['required', 'email'],
            'password' => ['required', 'string', PasswordRule::min(8)],
        ]);

        $status = DB::transaction(function () use ($request) {
            // Lock before the broker verifies/consumes the reset token. This also
            // serializes duplicate resets and prevents stale concurrent logins.
            $user = User::where('email', $request->input('email'))->lockForUpdate()->first();
            if (! $user) {
                return Password::INVALID_USER;
            }

            return Password::reset(
                $request->only('email', 'password', 'token'),
                function (User $user, string $password) {
                    $user->forceFill(['password' => Hash::make($password)])->save();
                    $user->tokens()->delete();
                    DB::table('mfa_enrollments')->where('user_id', $user->id)->delete();
                },
            );
        }, 3);

        if ($status !== Password::PASSWORD_RESET) {
            return response()->json([
                'message' => __($status),
            ], 422);
        }

        return response()->json([
            'message' => 'Your password has been reset successfully. You can now sign in with the new password.',
        ]);
    }
}
