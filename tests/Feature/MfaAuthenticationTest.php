<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\User;
use App\Services\MfaService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class MfaAuthenticationTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);

        $this->admin = User::where('role', UserRole::Admin)->first();
    }

    public function test_admin_login_without_mfa_returns_token_and_sets_cookie(): void
    {
        $response = $this->postJson('/api/login', [
            'email' => $this->admin->email,
            'password' => 'Password@123',
        ]);

        $response->assertOk()
            ->assertJsonPath('user.role', 'admin')
            ->assertJsonStructure(['user'])->assertJsonMissingPath('token')->assertJsonMissingPath('access_token');

        $response->assertCookie('fasre_session');
    }

    public function test_admin_mfa_setup_and_confirmation(): void
    {
        $token = $this->admin->createToken('admin')->plainTextToken;

        // 1. Setup
        $setupRes = $this->withToken($token)->postJson('/api/admin/mfa/setup');
        $setupRes->assertOk()
            ->assertJsonStructure(['secret', 'provisioning_uri', 'recovery_codes']);

        $secret = $setupRes->json('secret');
        $recoveryCodes = $setupRes->json('recovery_codes');
        $this->assertCount(8, $recoveryCodes);

        // 2. Confirm with valid code
        $code = MfaService::calculateTotp($secret);
        $confirmRes = $this->withToken($token)->postJson('/api/admin/mfa/confirm', [
            'secret' => $secret,
            'code' => $code,
            'recovery_codes' => $recoveryCodes,
        ]);

        $confirmRes->assertOk()->assertJsonPath('mfa_enabled', true);

        $this->admin->refresh();
        $this->assertTrue($this->admin->hasMfaEnabled());
        $this->assertCount(8, $this->admin->mfa_recovery_codes);
    }

    public function test_admin_login_with_mfa_requires_challenge(): void
    {
        $secret = MfaService::generateSecret();
        $this->admin->update([
            'mfa_secret' => $secret,
            'mfa_recovery_codes' => array_map(fn ($c) => Hash::make($c), MfaService::generateRecoveryCodes()),
            'mfa_enabled_at' => now(),
        ]);

        $response = $this->postJson('/api/login', [
            'email' => $this->admin->email,
            'password' => 'Password@123',
        ]);

        $response->assertOk()
            ->assertJsonPath('mfa_required', true)
            ->assertJsonStructure(['challenge_token', 'message']);

        // Must NOT issue full access token or session cookie yet
        $this->assertNull($response->json('token'));
        $response->assertCookieMissing('fasre_session');
    }

    public function test_challenge_token_cannot_access_protected_apis(): void
    {
        $challengeToken = $this->admin->createToken('mfa-challenge', ['mfa-challenge'])->plainTextToken;

        // Attempting to access admin or me endpoints with challenge token must fail
        $this->withToken($challengeToken)->getJson('/api/me')
            ->assertForbidden();

        $this->withToken($challengeToken)->getJson('/api/admin/users')
            ->assertForbidden();
    }

    public function test_mfa_challenge_verification_success_and_failure(): void
    {
        $secret = MfaService::generateSecret();
        $this->admin->update([
            'mfa_secret' => $secret,
            'mfa_recovery_codes' => [],
            'mfa_enabled_at' => now(),
        ]);

        $challengeToken = $this->admin->createToken('mfa-challenge', ['mfa-challenge'])->plainTextToken;

        // Invalid code
        $failRes = $this->postJson('/api/mfa/challenge', [
            'challenge_token' => $challengeToken,
            'code' => '000000',
        ]);
        $failRes->assertStatus(422);

        // Valid code
        $code = MfaService::calculateTotp($secret);
        $successRes = $this->postJson('/api/mfa/challenge', [
            'challenge_token' => $challengeToken,
            'code' => $code,
        ]);

        $successRes->assertOk()
            ->assertJsonPath('user.email', $this->admin->email)
            ->assertJsonStructure(['user'])->assertJsonMissingPath('token')->assertJsonMissingPath('access_token');

        $successRes->assertCookie('fasre_session');

        // Challenge token should be destroyed
        $reuseRes = $this->postJson('/api/mfa/challenge', [
            'challenge_token' => $challengeToken,
            'code' => $code,
        ]);
        $reuseRes->assertStatus(401);
    }

    public function test_mfa_recovery_code_redemption(): void
    {
        $secret = MfaService::generateSecret();
        $rawCode = 'ABCD-1234';
        $this->admin->update([
            'mfa_secret' => $secret,
            'mfa_recovery_codes' => [Hash::make($rawCode)],
            'mfa_enabled_at' => now(),
        ]);

        $challengeToken = $this->admin->createToken('mfa-challenge', ['mfa-challenge'])->plainTextToken;

        $response = $this->postJson('/api/mfa/recovery', [
            'challenge_token' => $challengeToken,
            'recovery_code' => $rawCode,
        ]);

        $response->assertOk()
            ->assertJsonPath('user.email', $this->admin->email)
            ->assertJsonPath('remaining_recovery_codes', 0);

        $response->assertCookie('fasre_session');

        $this->admin->refresh();
        $this->assertEmpty($this->admin->mfa_recovery_codes);
    }

    public function test_cookie_based_authentication_works_without_authorization_header(): void
    {
        $token = $this->admin->createToken('cookie-test')->plainTextToken;

        $response = $this->withCredentials()
            ->withUnencryptedCookie('fasre_session', Crypt::encryptString($token))
            ->getJson('/api/me');

        $response->assertOk()
            ->assertJsonPath('user.email', $this->admin->email);
    }

    public function test_tampered_or_invalid_session_cookie_fails_closed(): void
    {
        $response = $this->withCredentials()
            ->withUnencryptedCookie('fasre_session', 'tampered-invalid-cookie-value')
            ->getJson('/api/me');

        $response->assertStatus(401);
    }

    public function test_logout_clears_cookie_and_revokes_token(): void
    {
        $token = $this->admin->createToken('logout-test')->plainTextToken;

        $response = $this->withToken($token)
            ->withCookie('fasre_session', $token)
            ->postJson('/api/logout');

        $response->assertOk();
        $response->assertCookieExpired('fasre_session');
    }

    public function test_deactivated_account_is_blocked_and_token_revoked(): void
    {
        $token = $this->admin->createToken('active-check')->plainTextToken;

        // User is active: can access
        $this->withToken($token)->getJson('/api/me')->assertOk();

        // Deactivate user
        $this->admin->update(['is_active' => false]);

        // Next request with same token must fail with 403
        $res = $this->withToken($token)->getJson('/api/me');
        $res->assertStatus(403);

        // Token record should be deleted
        $this->assertDatabaseMissing('personal_access_tokens', [
            'tokenable_id' => $this->admin->id,
            'name' => 'active-check',
        ]);
    }

    public function test_caller_cannot_enroll_arbitrary_secret_without_pending_setup(): void
    {
        $token = $this->admin->createToken('test-token')->plainTextToken;

        // 1. Without prior setup, confirmation of an arbitrary secret is rejected
        $res = $this->withToken($token)->postJson('/api/admin/mfa/confirm', [
            'code' => '123456',
        ]);
        $res->assertStatus(422);

        // 2. Setup generates pending state on server
        $setupRes = $this->withToken($token)->postJson('/api/admin/mfa/setup')->assertOk();
        $realSecret = $setupRes->json('secret');

        // 3. Confirming an arbitrary mismatched secret is rejected
        $fakeSecret = MfaService::generateSecret();
        $resMismatched = $this->withToken($token)->postJson('/api/admin/mfa/confirm', [
            'secret' => $fakeSecret,
            'code' => MfaService::calculateTotp($fakeSecret),
        ]);
        $resMismatched->assertStatus(422);

        // Active MFA remains not enabled
        $this->admin->refresh();
        $this->assertFalse($this->admin->hasMfaEnabled());
    }

    public function test_mfa_replacement_requires_existing_factor_and_preserves_configuration_on_failure(): void
    {
        $secretA = MfaService::generateSecret();
        $this->admin->update([
            'mfa_secret' => $secretA,
            'mfa_recovery_codes' => [Hash::make('OLD-RECO-VERY')],
            'mfa_enabled_at' => now(),
        ]);

        $token = $this->admin->createToken('admin-token')->plainTextToken;

        // Replacement attempt with invalid current factor
        $resFailed = $this->withToken($token)->postJson('/api/admin/mfa/setup', [
            'current_password' => 'WrongPassword',
            'current_code' => '999999',
        ]);
        $resFailed->assertStatus(403);

        // Previous configuration preserved
        $this->admin->refresh();
        $this->assertTrue(MfaService::verifyTotp($this->admin->mfa_secret, MfaService::calculateTotp($secretA)));

        // Setup replacement with valid current factor
        $resSetup = $this->withToken($token)->postJson('/api/admin/mfa/setup', [
            'current_password' => 'Password@123',
            'current_code' => MfaService::calculateTotp($secretA),
        ])->assertOk();

        $secretB = $resSetup->json('secret');

        // Confirm replacement with valid current factor and new TOTP
        $resConfirm = $this->withToken($token)->postJson('/api/admin/mfa/confirm', [
            'current_password' => 'Password@123',
            'current_code' => MfaService::calculateTotp($secretA),
            'code' => MfaService::calculateTotp($secretB),
        ])->assertOk();

        $this->admin->refresh();
        $this->assertTrue(MfaService::verifyTotp($this->admin->mfa_secret, MfaService::calculateTotp($secretB)));
    }

    public function test_cookie_authenticated_mutations_require_valid_csrf_token(): void
    {
        $token = $this->admin->createToken('cookie-csrf-test')->plainTextToken;
        $encryptedCookie = Crypt::encryptString($token);
        $validCsrf = hash_hmac('sha256', 'csrf:'.$token, config('app.key'));

        // 1. Cookie mutation without CSRF token -> rejected with 419
        $resNoCsrf = $this->withCredentials()
            ->withUnencryptedCookie('fasre_session', $encryptedCookie)
            ->patchJson('/api/notifications/read-all');
        $resNoCsrf->assertStatus(419);

        // 2. Cookie mutation with invalid CSRF token -> rejected with 419
        $resInvalidCsrf = $this->withCredentials()
            ->withUnencryptedCookie('fasre_session', $encryptedCookie)
            ->withHeader('X-CSRF-TOKEN', 'invalid-csrf-token')
            ->patchJson('/api/notifications/read-all');
        $resInvalidCsrf->assertStatus(419);

        // 3. Cookie mutation from disallowed Origin -> rejected with 403
        $resDisallowedOrigin = $this->withCredentials()
            ->withUnencryptedCookie('fasre_session', $encryptedCookie)
            ->withHeader('X-CSRF-TOKEN', $validCsrf)
            ->withHeader('Origin', 'https://malicious-attacker.com')
            ->patchJson('/api/notifications/read-all');
        $resDisallowedOrigin->assertStatus(403);

        // 4. Cookie mutation with valid CSRF token and allowed origin -> succeeds
        $resSuccess = $this->withCredentials()
            ->withUnencryptedCookie('fasre_session', $encryptedCookie)
            ->withHeader('X-CSRF-TOKEN', $validCsrf)
            ->withHeader('Origin', 'http://localhost:5173')
            ->patchJson('/api/notifications/read-all');
        $resSuccess->assertOk();

        // 5. Native mobile client with Bearer token (no cookie) does not require CSRF
        $resBearer = $this->withToken($token)
            ->patchJson('/api/notifications/read-all');
        $resBearer->assertOk();
    }

    public function test_unenrolled_admin_blocked_from_admin_apis_when_mfa_enforced(): void
    {
        $this->admin->update(['mfa_required' => true, 'mfa_secret' => null, 'mfa_enabled_at' => null]);
        $token = $this->admin->createToken('admin-mfa-required')->plainTextToken;

        // Access to admin resources is blocked
        $resBlocked = $this->withToken($token)->getJson('/api/admin/users');
        $resBlocked->assertStatus(403)
            ->assertJsonPath('mfa_enrollment_required', true);

        // Access to MFA setup and status is permitted
        $statusRes = $this->withToken($token)->getJson('/api/admin/mfa/status')->assertOk();
        $this->assertFalse($statusRes->json('mfa_enabled'));

        $setupRes = $this->withToken($token)->postJson('/api/admin/mfa/setup')->assertOk();
        $secret = $setupRes->json('secret');

        // Complete enrollment
        $confirmed = $this->withToken($token)->postJson('/api/admin/mfa/confirm', [
            'code' => MfaService::calculateTotp($secret),
        ])->assertOk();

        // Only the rotated cookie session can access admin APIs.
        $cookie = collect($confirmed->headers->getCookies())->first(fn ($c) => $c->getName() === 'fasre_session');
        $this->app['auth']->forgetGuards();
        $this->flushHeaders();
        $resUnblocked = $this->withCredentials()->withUnencryptedCookie('fasre_session', $cookie->getValue())->getJson('/api/admin/users');
        $resUnblocked->assertOk();
    }
}
