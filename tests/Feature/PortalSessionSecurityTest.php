<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\User;
use App\Services\MfaService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\TestCase;

class PortalSessionSecurityTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->admin = User::where('role', UserRole::Admin)->firstOrFail();
    }

    private function loginCookie(): string
    {
        $res = $this->postJson('/api/login', ['email' => $this->admin->email, 'password' => 'Password@123'])->assertOk();
        $res->assertJsonMissingPath('token')->assertJsonMissingPath('access_token')->assertJsonMissingPath('data.token');

        return collect($res->headers->getCookies())->first(fn ($c) => $c->getName() === 'fasre_session')->getValue();
    }

    private function useCookie(string $cookie): void
    {
        $this->app['auth']->forgetGuards();
        $this->flushHeaders();
        $this->withCredentials()->withUnencryptedCookie('fasre_session', $cookie)
            ->withHeader('X-CSRF-TOKEN', hash_hmac('sha256', 'csrf:'.Crypt::decryptString($cookie), config('app.key')));
    }

    public function test_cookie_enrollment_rotation_and_logout_end_to_end(): void
    {
        config(['auth.mfa_enforced' => true]);
        $old = $this->loginCookie();
        $this->useCookie($old);
        $this->getJson('/api/admin/users')->assertForbidden();
        $setup = $this->postJson('/api/admin/mfa/setup')->assertOk()->json();
        $stored = DB::table('mfa_enrollments')->where('user_id', $this->admin->id)->first();
        $this->assertStringNotContainsString($setup['secret'], $stored->payload);
        $payload = json_decode(Crypt::decryptString($stored->payload), true);
        $this->assertSame($setup['secret'], $payload['secret']);
        $confirmed = $this->postJson('/api/admin/mfa/confirm', ['code' => MfaService::calculateTotp($setup['secret'])])->assertOk();
        $confirmed->assertJsonMissingPath('token')->assertJsonMissingPath('data.token');
        $this->assertDatabaseMissing('mfa_enrollments', ['user_id' => $this->admin->id]);
        $new = collect($confirmed->headers->getCookies())->first(fn ($c) => $c->getName() === 'fasre_session')->getValue();
        $this->useCookie($old);
        $this->getJson('/api/admin/users')->assertUnauthorized();
        $this->useCookie($new);
        $this->getJson('/api/admin/users')->assertOk();
        $this->postJson('/api/logout')->assertOk()->assertCookieExpired('fasre_session')->assertCookieExpired('fasre_csrf');
        $this->useCookie($new);
        $this->getJson('/api/me')->assertUnauthorized();
    }

    public function test_portal_cookie_expiry_is_enforced_server_side_and_cannot_be_used_as_bearer(): void
    {
        $cookie = $this->loginCookie();
        $token = Crypt::decryptString($cookie);
        $this->withToken($token)->getJson('/api/me')->assertUnauthorized();
        $this->travel(121)->minutes();
        $this->useCookie($cookie);
        $this->getJson('/api/me')->assertUnauthorized();
    }

    public function test_disable_rotates_current_session_and_removes_pending_enrollment(): void
    {
        $secret = MfaService::generateSecret();
        $this->admin->update(['mfa_secret' => $secret, 'mfa_enabled_at' => now()]);
        $old = $this->admin->createToken('old-admin')->plainTextToken;
        $this->withToken($old)->postJson('/api/admin/mfa/setup', [
            'current_password' => 'Password@123', 'current_code' => MfaService::calculateTotp($secret),
        ])->assertOk();
        $this->withToken($old)->postJson('/api/admin/mfa/disable', [
            'password' => 'Password@123', 'code' => MfaService::calculateTotp($secret),
        ])->assertOk()->assertCookie('fasre_session')->assertJsonMissingPath('token');
        $this->assertTrue(PersonalAccessToken::findToken($old) === null);
        $this->assertDatabaseMissing('mfa_enrollments', ['user_id' => $this->admin->id]);
        $this->assertFalse($this->admin->fresh()->hasMfaEnabled());
    }

    public function test_origin_checks_match_scheme_and_port_and_csrf_survives_testing_environment(): void
    {
        config(['cors.allowed_origins' => ['https://campus.example:443']]);
        $cookie = $this->loginCookie();
        foreach (['http://campus.example:443', 'https://campus.example:444', 'https://other.example'] as $origin) {
            $this->useCookie($cookie);
            $this->withHeader('Origin', $origin)->patchJson('/api/notifications/read-all')->assertForbidden();
        }
        $this->useCookie($cookie);
        $this->withHeader('Origin', 'https://campus.example:443')->patchJson('/api/notifications/read-all')->assertOk();
        $this->useCookie($cookie);
        $this->flushHeaders();
        $this->patchJson('/api/notifications/read-all')->assertStatus(419);
    }

    public function test_mobile_login_keeps_bearer_contract_without_browser_cookies(): void
    {
        foreach ([UserRole::Faculty, UserRole::Student] as $role) {
            $user = User::where('role', $role)->firstOrFail();
            $this->app['auth']->forgetGuards();
            $this->flushHeaders();
            $res = $this->postJson('/api/login', ['email' => $user->email, 'password' => 'Password@123'])
                ->assertOk()->assertCookieMissing('fasre_session')->assertCookieMissing('fasre_csrf');
            $this->withToken($res->json('token'))->getJson('/api/me')->assertOk()->assertJsonPath('user.id', $user->id);
        }
    }
}
