<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;

class PasswordResetSecurityTest extends TestCase
{
    use RefreshDatabase;

    public function test_reset_revokes_sessions_challenges_and_pending_enrollment(): void
    {
        $user = User::factory()->create(['role' => UserRole::Admin, 'is_central_qa' => true, 'department_id' => null]);
        $session = $user->createToken('admin')->plainTextToken;
        $this->withToken($session)->postJson('/api/admin/mfa/setup')->assertOk();
        $user->createToken('mfa-challenge', ['mfa-challenge'], now()->addMinutes(5));
        $reset = Password::createToken($user);
        $this->postJson('/api/reset-password', ['email' => $user->email, 'token' => $reset, 'password' => 'ReplacementPassword123!'])->assertOk();
        $this->assertTrue(Hash::check('ReplacementPassword123!', $user->fresh()->password));
        $this->assertSame(0, $user->tokens()->count());
        $this->assertDatabaseMissing('mfa_enrollments', ['user_id' => $user->id]);
        Auth::forgetGuards();
        $this->withToken($session)->getJson('/api/me')->assertUnauthorized();
    }

    public function test_consumed_reset_link_cannot_replace_the_password_again(): void
    {
        $user = User::factory()->create();
        $token = Password::createToken($user);
        $this->postJson('/api/reset-password', ['email' => $user->email, 'token' => $token, 'password' => 'FirstPassword123!'])->assertOk();
        $this->postJson('/api/reset-password', ['email' => $user->email, 'token' => $token, 'password' => 'SecondPassword123!'])->assertUnprocessable();
        $this->assertTrue(Hash::check('FirstPassword123!', $user->fresh()->password));
    }
}
