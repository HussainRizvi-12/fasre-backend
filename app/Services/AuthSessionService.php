<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;

class AuthSessionService
{
    public static function issue(User $user, Request $request, array $extra = []): JsonResponse
    {
        $browser = $user->isAdmin();
        $minutes = (int) config('auth.portal_session_minutes', 120);
        $token = $user->createToken($browser ? 'portal-session' : 'auth-token', ['*'],
            $browser ? now()->addMinutes($minutes) : null)->plainTextToken;
        $body = array_merge(['user' => $user, 'data' => ['user' => $user]], $extra);
        if (! $browser) {
            $body['token'] = $body['access_token'] = $token;
            $body['data']['token'] = $token;

            return response()->json($body)->header('Cache-Control', 'no-store');
        }
        $secure = $request->isSecure() || app()->environment('production') || config('session.secure', false);
        $csrf = hash_hmac('sha256', 'csrf:'.$token, config('app.key'));

        return response()->json($body)->header('Cache-Control', 'no-store')
            ->withCookie(cookie('fasre_session', Crypt::encryptString($token), $minutes, '/', null, $secure, true, false, 'Lax'))
            ->withCookie(cookie('fasre_csrf', $csrf, $minutes, '/', null, $secure, false, false, 'Lax'));
    }
}
