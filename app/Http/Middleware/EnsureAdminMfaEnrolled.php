<?php

namespace App\Http\Middleware;

use App\Enums\UserRole;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureAdminMfaEnrolled
{
    /**
     * Enforce that administrators must have completed MFA enrollment before
     * accessing administrative resources, when MFA enforcement is active.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && $user->role === UserRole::Admin) {
            $isMfaEnforced = config('auth.mfa_enforced', false) || (bool) ($user->mfa_required ?? false);

            if ($isMfaEnforced && ! $user->hasMfaEnabled()) {
                // Allow only MFA management and session retrieval/termination endpoints
                if ($request->is('api/admin/mfa*') || $request->is('api/me') || $request->is('api/logout')) {
                    return $next($request);
                }

                return response()->json([
                    'message' => 'Multi-factor authentication enrollment is mandatory for administrator accounts. Please complete setup in Account Security.',
                    'mfa_enrollment_required' => true,
                ], 403);
            }
        }

        return $next($request);
    }
}
