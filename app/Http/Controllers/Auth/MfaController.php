<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\AuthSessionService;
use App\Services\MfaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\PersonalAccessToken;

class MfaController extends Controller
{
    /**
     * POST /api/mfa/challenge
     * Verify a 6-digit TOTP code with a valid MFA challenge token.
     */
    public function challenge(Request $request): JsonResponse
    {
        return DB::transaction(function () use ($request) {
            $validated = $request->validate([
                'challenge_token' => ['required', 'string'],
                'code' => ['required', 'string', 'size:6'],
            ]);

            $tokenModel = PersonalAccessToken::findToken($validated['challenge_token']);

            if (! $tokenModel || $tokenModel->name !== 'mfa-challenge' || ! $tokenModel->can('mfa-challenge')) {
                return response()->json([
                    'message' => 'Invalid or expired MFA challenge session.',
                ], 401);
            }

            User::whereKey($tokenModel->tokenable_id)->lockForUpdate()->firstOrFail();
            // Lock the challenge token row to coordinate atomic single-use consumption
            $tokenModel = PersonalAccessToken::where('id', $tokenModel->id)->lockForUpdate()->first();

            if (! $tokenModel || $tokenModel->name !== 'mfa-challenge') {
                return response()->json([
                    'message' => 'Invalid or expired MFA challenge session.',
                ], 401);
            }

            if ($tokenModel->expires_at && $tokenModel->expires_at->isPast()) {
                $tokenModel->delete();

                return response()->json([
                    'message' => 'MFA challenge has expired. Please log in again.',
                ], 401);
            }

            /** @var User|null $user */
            $user = $tokenModel->tokenable;

            if (! $user || ! $user->is_active) {
                $tokenModel->delete();

                return response()->json([
                    'message' => 'Account is inactive or could not be found.',
                ], 401);
            }

            if (! MfaService::verifyTotp($user->mfa_secret ?? '', $validated['code'])) {
                return response()->json([
                    'message' => 'Invalid verification code. Please check your authenticator app and try again.',
                ], 422);
            }

            // Successfully verified: destroy challenge token so no concurrent request can reuse it
            $tokenModel->delete();

            return AuthSessionService::issue($user, $request, ['message' => 'MFA verification successful.']);
        });
    }

    /**
     * POST /api/mfa/recovery
     * Redeem a single-use recovery code with a valid MFA challenge token.
     */
    public function recovery(Request $request): JsonResponse
    {
        return DB::transaction(function () use ($request) {
            $validated = $request->validate([
                'challenge_token' => ['required', 'string'],
                'recovery_code' => ['required', 'string', 'min:8', 'max:20'],
            ]);

            $tokenModel = PersonalAccessToken::findToken($validated['challenge_token']);

            if (! $tokenModel || $tokenModel->name !== 'mfa-challenge' || ! $tokenModel->can('mfa-challenge')) {
                return response()->json([
                    'message' => 'Invalid or expired MFA challenge session.',
                ], 401);
            }

            User::whereKey($tokenModel->tokenable_id)->lockForUpdate()->firstOrFail();
            // Lock challenge token row to prevent concurrent redemption
            $tokenModel = PersonalAccessToken::where('id', $tokenModel->id)->lockForUpdate()->first();

            if (! $tokenModel || $tokenModel->name !== 'mfa-challenge') {
                return response()->json([
                    'message' => 'Invalid or expired MFA challenge session.',
                ], 401);
            }

            if ($tokenModel->expires_at && $tokenModel->expires_at->isPast()) {
                $tokenModel->delete();

                return response()->json([
                    'message' => 'MFA challenge has expired. Please log in again.',
                ], 401);
            }

            /** @var User|null $user */
            $user = $tokenModel->tokenable;

            if (! $user || ! $user->is_active) {
                $tokenModel->delete();

                return response()->json([
                    'message' => 'Account is inactive or could not be found.',
                ], 401);
            }

            if (! MfaService::verifyAndConsumeRecoveryCode($user, $validated['recovery_code'])) {
                return response()->json([
                    'message' => 'Invalid or already used recovery code.',
                ], 422);
            }

            // Successfully redeemed: destroy challenge token
            $tokenModel->delete();

            return AuthSessionService::issue($user, $request, [
                'message' => 'Recovery code accepted.',
                'remaining_recovery_codes' => count($user->mfa_recovery_codes ?? []),
            ]);
        });
    }

    /**
     * POST /api/admin/mfa/setup
     * Initiate MFA enrollment for the authenticated admin with server-side pending state.
     */
    private function lockedUser(Request $request): User
    {
        $user = User::whereKey($request->user()->id)->lockForUpdate()->firstOrFail();
        $tokenId = $request->user()->currentAccessToken()?->id;
        abort_unless($user->is_active && $tokenId && $user->tokens()->whereKey($tokenId)->exists(), 401);

        return $user;
    }

    private function verifyExistingFactor(User $user, Request $request): void
    {
        if (! $user->hasMfaEnabled()) {
            return;
        }
        abort_unless(is_string($request->input('current_password')) &&
            Hash::check($request->input('current_password'), $user->password) &&
            is_string($request->input('current_code')) &&
            MfaService::verifyTotp($user->mfa_secret, $request->input('current_code')),
            403, 'Current password and authenticator code are required.');
    }

    public function setup(Request $request): JsonResponse
    {
        return DB::transaction(function () use ($request) {
            $user = $this->lockedUser($request);
            $this->verifyExistingFactor($user, $request);
            $secret = MfaService::generateSecret();
            $codes = MfaService::generateRecoveryCodes();
            DB::table('mfa_enrollments')->updateOrInsert(
                ['user_id' => $user->id],
                ['token_id' => $request->user()->currentAccessToken()->id,
                    'payload' => Crypt::encryptString(json_encode(['secret' => $secret, 'recovery_codes' => $codes], JSON_THROW_ON_ERROR)),
                    'expires_at' => now()->addMinutes(10)]
            );

            return response()->json([
                'secret' => $secret,
                'provisioning_uri' => MfaService::getProvisioningUri($secret, $user->email),
                'recovery_codes' => $codes,
            ])->header('Cache-Control', 'no-store');
        });
    }

    public function confirm(Request $request): JsonResponse
    {
        $request->validate(['code' => ['required', 'string', 'regex:/^[0-9]{6}$/']]);

        return DB::transaction(function () use ($request) {
            $user = $this->lockedUser($request);
            $this->verifyExistingFactor($user, $request);
            $pending = DB::table('mfa_enrollments')->where('user_id', $user->id)->first();
            if (! $pending || $pending->token_id != $request->user()->currentAccessToken()->id ||
                Carbon::parse($pending->expires_at)->lte(now())) {
                throw ValidationException::withMessages(['code' => ['Enrollment is missing, expired, or belongs to another session. Start setup again.']]);
            }
            $payload = json_decode(Crypt::decryptString($pending->payload), true, 512, JSON_THROW_ON_ERROR);
            if (($request->filled('secret') && $request->input('secret') !== $payload['secret']) ||
                ! MfaService::verifyTotp($payload['secret'], $request->input('code'))) {
                throw ValidationException::withMessages(['code' => ['Invalid verification code or enrollment secret.']]);
            }
            $user->update([
                'mfa_secret' => $payload['secret'],
                'mfa_recovery_codes' => array_map(fn ($c) => Hash::make($c), $payload['recovery_codes']),
                'mfa_enabled_at' => now(),
            ]);
            DB::table('mfa_enrollments')->where('user_id', $user->id)->delete();
            $user->tokens()->delete();

            return AuthSessionService::issue($user, $request, [
                'message' => 'Multi-factor authentication enabled.', 'mfa_enabled' => true,
                'mfa_enabled_at' => $user->mfa_enabled_at->toIso8601String(),
            ]);
        });
    }

    public function disable(Request $request): JsonResponse
    {
        $request->validate(['password' => ['required', 'string'], 'code' => ['required', 'string', 'regex:/^[0-9]{6}$/']]);

        return DB::transaction(function () use ($request) {
            $user = $this->lockedUser($request);
            abort_unless($user->hasMfaEnabled() && Hash::check($request->input('password'), $user->password) &&
                MfaService::verifyTotp($user->mfa_secret, $request->input('code')), 403, 'Current password and authenticator code are required.');
            $user->update(['mfa_secret' => null, 'mfa_recovery_codes' => null, 'mfa_enabled_at' => null]);
            DB::table('mfa_enrollments')->where('user_id', $user->id)->delete();
            $user->tokens()->delete();

            return AuthSessionService::issue($user, $request, ['message' => 'MFA disabled. Re-enrollment is required where policy applies.', 'mfa_enabled' => false]);
        });
    }

    public function status(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'mfa_enabled' => $user->hasMfaEnabled(),
            'mfa_required' => (bool) config('auth.mfa_enforced') || (bool) $user->mfa_required,
            'mfa_enabled_at' => $user->mfa_enabled_at?->toIso8601String(),
            'remaining_recovery_codes' => count($user->mfa_recovery_codes ?? []),
        ])->header('Cache-Control', 'no-store');
    }
}
