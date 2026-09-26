<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateFromSessionCookie
{
    /**
     * If the incoming request contains a fasre_session cookie and lacks
     * an explicit Authorization header, populate the Bearer token header
     * so Sanctum can authenticate cookie-based portal sessions seamlessly.
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->headers->has('Authorization') && $request->cookie('fasre_session')) {
            $sessionCookie = $request->cookie('fasre_session');
            if (! empty($sessionCookie)) {
                try {
                    $sessionToken = Crypt::decryptString($sessionCookie);
                } catch (\Throwable) {
                    // Fail closed: Never silently accept plaintext or tampered cookies
                    return response()->json([
                        'message' => 'Invalid or tampered session cookie.',
                    ], 401)->withoutCookie('fasre_session');
                }

                if (empty($sessionToken)) {
                    return response()->json([
                        'message' => 'Invalid or tampered session cookie.',
                    ], 401)->withoutCookie('fasre_session');
                }

                // CSRF Protection for state-changing cookie-authenticated requests
                if (in_array($request->method(), ['POST', 'PUT', 'PATCH', 'DELETE'])) {
                    // 1. Origin header verification against allowed origins
                    $origin = $request->headers->get('Origin');
                    if ($origin) {
                        $isAllowed = in_array($origin, (array) config('cors.allowed_origins', []), true);

                        if (! $isAllowed) {
                            return response()->json([
                                'message' => 'Cross-origin request rejected from disallowed origin.',
                            ], 403);
                        }
                    }

                    // 2. CSRF Token verification
                    $providedCsrf = $request->header('X-CSRF-TOKEN') ?: $request->header('X-XSRF-TOKEN');
                    $expectedCsrf = hash_hmac('sha256', 'csrf:'.$sessionToken, config('app.key'));

                    if (! $providedCsrf || ! hash_equals($expectedCsrf, (string) $providedCsrf)) {
                        return response()->json([
                            'message' => 'CSRF token mismatch. Please refresh and try again.',
                        ], 419);
                    }
                }

                $request->attributes->set('fasre_cookie_authenticated', true);
                $request->headers->set('Authorization', 'Bearer '.$sessionToken);
            }
        }

        return $next($request);
    }
}
