<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserIsAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->user() || ! $request->user()->isAdmin()) {
            if ($request->expectsJson() || $request->is('api/*')) {
                return response()->json([
                    'message' => 'Forbidden. Admin access required.',
                ], 403);
            }

            abort(403, 'Forbidden. Admin access required.');
        }

        if (! $request->user()->is_active) {
            if ($request->expectsJson() || $request->is('api/*')) {
                return response()->json([
                    'message' => 'Your account has been deactivated. Please contact the administrator.',
                ], 403);
            }

            abort(403, 'Your account has been deactivated.');
        }

        return $next($request);
    }
}
