<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthAndCrossRoleMatrixTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;
    protected User $faculty;
    protected User $student;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);

        $this->admin = User::where('role', UserRole::Admin)->first();
        $this->faculty = User::where('role', UserRole::Faculty)->first();
        $this->student = User::where('role', UserRole::Student)->first();
    }

    public function test_admin_login_and_me(): void
    {
        $res = $this->postJson('/api/login', [
            'email' => $this->admin->email,
            'password' => 'Password@123',
        ]);
        $res->assertOk()->assertJsonPath('user.role', 'admin');
        $token = $res->json('token');

        $this->withToken($token)->getJson('/api/me')
            ->assertOk()
            ->assertJsonPath('user.role', 'admin');
    }

    public function test_faculty_login_and_me(): void
    {
        $res = $this->postJson('/api/login', [
            'email' => $this->faculty->email,
            'password' => 'Password@123',
        ]);
        $res->assertOk()->assertJsonPath('user.role', 'faculty');
        $token = $res->json('token');

        $this->withToken($token)->getJson('/api/me')
            ->assertOk()
            ->assertJsonPath('user.role', 'faculty');
    }

    public function test_student_login_and_me(): void
    {
        $res = $this->postJson('/api/login', [
            'email' => $this->student->email,
            'password' => 'Password@123',
        ]);
        $res->assertOk()->assertJsonPath('user.role', 'student');
        $token = $res->json('token');

        $this->withToken($token)->getJson('/api/me')
            ->assertOk()
            ->assertJsonPath('user.role', 'student');
    }

    public function test_logout_revokes_token(): void
    {
        $token = $this->student->createToken('logout_test')->plainTextToken;

        $this->assertDatabaseHas('personal_access_tokens', ['name' => 'logout_test']);

        $res = $this->withToken($token)->postJson('/api/logout');
        $res->assertOk()->assertJson(['message' => 'Logged out successfully.']);

        // Confirm database token record is deleted
        $this->assertDatabaseMissing('personal_access_tokens', ['name' => 'logout_test']);
    }

    public function test_cross_role_gating_blocks_admin_from_student_and_faculty_routes(): void
    {
        $adminToken = $this->admin->createToken('admin')->plainTextToken;

        $this->withToken($adminToken)->getJson('/api/admin/users')->assertOk();
        $this->withToken($adminToken)->getJson('/api/student/enrolled-sections')->assertForbidden();
        $this->withToken($adminToken)->getJson('/api/faculty/assigned-audits')->assertForbidden();
    }

    public function test_cross_role_gating_blocks_faculty_from_admin_and_student_routes(): void
    {
        $facultyToken = $this->faculty->createToken('faculty')->plainTextToken;

        $this->withToken($facultyToken)->getJson('/api/faculty/assigned-audits')->assertOk();
        $this->withToken($facultyToken)->getJson('/api/admin/users')->assertForbidden();
        $this->withToken($facultyToken)->getJson('/api/student/enrolled-sections')->assertForbidden();
    }

    public function test_cross_role_gating_blocks_student_from_admin_and_faculty_routes(): void
    {
        $studentToken = $this->student->createToken('student')->plainTextToken;

        $this->withToken($studentToken)->getJson('/api/student/enrolled-sections')->assertOk();
        $this->withToken($studentToken)->getJson('/api/admin/users')->assertForbidden();
        $this->withToken($studentToken)->getJson('/api/faculty/assigned-audits')->assertForbidden();
    }

    public function test_login_rejects_invalid_password_with_401(): void
    {
        $res = $this->postJson('/api/login', [
            'email' => $this->faculty->email,
            'password' => 'WrongPassword!123',
        ]);
        $res->assertStatus(401)
            ->assertJson(['message' => 'Invalid email or password.']);
    }

    public function test_login_rejects_inactive_user_with_401(): void
    {
        $this->faculty->update(['is_active' => false]);

        $res = $this->postJson('/api/login', [
            'email' => $this->faculty->email,
            'password' => 'Password@123',
        ]);
        $res->assertStatus(401)
            ->assertJsonPath('message', 'Your account has been deactivated. Please contact the administrator.');
    }

    public function test_login_rate_limiting_throttles_after_10_attempts(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $this->postJson('/api/login', [
                'email' => 'unknown@fasre.test',
                'password' => 'Password@123',
            ]);
        }

        $res = $this->postJson('/api/login', [
            'email' => 'unknown@fasre.test',
            'password' => 'Password@123',
        ]);
        $res->assertStatus(429);
    }
}

