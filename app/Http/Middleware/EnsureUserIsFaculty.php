<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserIsFaculty
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->user() || ! $request->user()->isFaculty()) {
            if ($request->expectsJson() || $request->is('api/*')) {
                return response()->json([
                    'message' => 'Forbidden. Faculty access required.',
                ], 403);
            }

            abort(403, 'Forbidden. Faculty access required.');
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
