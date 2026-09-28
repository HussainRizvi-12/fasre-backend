<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureCentralQa
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless($request->user()?->isCentralQa(), 403, 'Central QA access is required for institution-wide administration.');

        return $next($request);
    }
}
