<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureAccountIsActive
{
    /**
     * Verify that the authenticated user account is active, and ensure
     * temporary MFA challenge tokens cannot be used to access regular APIs.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user) {
            // Block and revoke inactive users (evaluating fresh state against ground-truth database)
            $freshUser = $user->fresh();
            if (! $freshUser || ! $freshUser->is_active) {
                $user->currentAccessToken()?->delete();

                return response()->json([
                    'message' => 'Your account has been deactivated. Please contact the administrator.',
                ], 403)->withoutCookie('fasre_session');
            }

            // Block restricted challenge tokens from accessing general authenticated endpoints
            $token = $user->currentAccessToken();
            if ($token && $token->name === 'portal-session' && ! $request->attributes->get('fasre_cookie_authenticated')) {
                return response()->json(['message' => 'Portal sessions require cookie authentication.'], 401);
            }
            if ($token && $token->name === 'mfa-challenge') {
                return response()->json([
                    'message' => 'MFA verification required. Please complete the MFA challenge.',
                ], 403);
            }
        }

        return $next($request);
    }
}
