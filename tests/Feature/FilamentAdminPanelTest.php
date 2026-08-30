<?php

namespace Tests\Feature;

use App\Enums\ReviewWindowStatus;
use App\Enums\UserRole;
use App\Models\Question;
use App\Models\ReviewResponse;
use App\Models\ReviewWindow;
use App\Models\Section;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class FilamentAdminPanelTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    public function test_root_redirects_to_admin(): void
    {
        $response = $this->get('/');
        $response->assertRedirect('/admin');
    }

    public function test_unauthenticated_user_redirected_to_filament_login(): void
    {
        $response = $this->get('/admin');
        $response->assertRedirect('/admin/login');
    }

    public function test_filament_login_page_renders(): void
    {
        $response = $this->get('/admin/login');
        $response->assertSuccessful();
    }

    public function test_admin_can_access_admin_dashboard(): void
    {
        $admin = User::where('role', UserRole::Admin)->first();

        $response = $this->actingAs($admin)->get('/admin');

        $response->assertSuccessful();
    }

    public function test_faculty_cannot_access_admin_panel(): void
    {
        $faculty = User::where('role', UserRole::Faculty)->first();

        $response = $this->actingAs($faculty)->get('/admin');

        $response->assertForbidden();
    }

    public function test_student_cannot_access_admin_panel(): void
    {
        $student = User::where('role', UserRole::Student)->first();

        $response = $this->actingAs($student)->get('/admin');

        $response->assertForbidden();
    }

    public function test_admin_can_access_all_resources_and_pages(): void
    {
        $admin = User::where('role', UserRole::Admin)->first();

        $endpoints = [
            '/admin',
            '/admin/users',
            '/admin/departments',
            '/admin/courses',
            '/admin/sections',
            '/admin/faculty-assignments',
            '/admin/student-enrollments',
            '/admin/review-questions',
            '/admin/audit-questions',
            '/admin/review-windows',
            '/admin/review-results',
            '/admin/audit-assignments',
            '/admin/audit-submissions',
        ];

        foreach ($endpoints as $url) {
            $response = $this->actingAs($admin)->get($url);
            $response->assertSuccessful();
        }
    }

    public function test_review_window_lifecycle_state_machine(): void
    {
        $window = ReviewWindow::create([
            'title' => 'Test Window',
            'starts_at' => now(),
            'ends_at' => now()->addDays(7),
            'status' => ReviewWindowStatus::Draft,
        ]);

        $this->assertEquals(ReviewWindowStatus::Draft, $window->status);

        // Transition: Draft -> Active
        $window->update(['status' => ReviewWindowStatus::Active]);
        $this->assertEquals(ReviewWindowStatus::Active, $window->fresh()->status);

        // Transition: Active -> Closed
        $window->update(['status' => ReviewWindowStatus::Closed]);
        $this->assertEquals(ReviewWindowStatus::Closed, $window->fresh()->status);

        // Transition: Closed -> Published
        $window->update(['status' => ReviewWindowStatus::Published]);
        $this->assertEquals(ReviewWindowStatus::Published, $window->fresh()->status);
    }

    public function test_activating_second_review_window_is_blocked_while_one_is_active(): void
    {
        $admin = User::where('role', UserRole::Admin)->first();
        $adminToken = $admin->createToken('admin_test')->plainTextToken;

        // Note: DemoSeeder already creates 1 Active window
        $draftWindow = ReviewWindow::create([
            'title' => 'Concurrent Window Attempt',
            'starts_at' => now(),
            'ends_at' => now()->addDays(14),
            'status' => ReviewWindowStatus::Draft,
        ]);

        // Attempting to activate should fail with 422
        $response = $this->withToken($adminToken)
            ->postJson("/api/admin/review-windows/{$draftWindow->id}/activate");

        $response->assertStatus(422)
            ->assertJson([
                'message' => 'Cannot activate review window: another review window is currently active. Close the active window first.',
            ]);

        // Close the existing active window
        $existingActive = ReviewWindow::where('status', ReviewWindowStatus::Active)->first();
        $this->withToken($adminToken)
            ->postJson("/api/admin/review-windows/{$existingActive->id}/close")
            ->assertOk();

        // Now activating the draft window succeeds
        $this->withToken($adminToken)
            ->postJson("/api/admin/review-windows/{$draftWindow->id}/activate")
            ->assertOk();

        $this->assertEquals(ReviewWindowStatus::Active, $draftWindow->fresh()->status);
    }

    public function test_filament_review_results_page_enforces_suppression(): void
    {
        $admin = User::where('role', UserRole::Admin)->first();
        $window = ReviewWindow::create([
            'title' => 'Fall 2026 Suppression Test',
            'starts_at' => now()->subDays(5),
            'ends_at' => now()->addDays(5),
            'status' => ReviewWindowStatus::Active,
        ]);

        $section = Section::first();

        // 4 responses (below suppression threshold of 5)
        for ($i = 0; $i < 4; $i++) {
            ReviewResponse::create([
                'review_window_id' => $window->id,
                'section_id' => $section->id,
                'pseudonym_token' => (string) Str::uuid(),
                'answers_json' => ['1' => 5, '2' => true, '3' => 5, '4' => 'Good'],
                'submitted_at' => now()->startOfDay(),
            ]);
        }

        $res = $this->actingAs($admin)->get("/admin/review-results?review_window_id={$window->id}&section_id={$section->id}");
        $res->assertSuccessful();
        $res->assertSee('Results suppressed — fewer than 5 responses');

        // 5th response
        ReviewResponse::create([
            'review_window_id' => $window->id,
            'section_id' => $section->id,
            'pseudonym_token' => (string) Str::uuid(),
            'answers_json' => ['1' => 5, '2' => true, '3' => 5, '4' => 'Excellent'],
            'submitted_at' => now()->startOfDay(),
        ]);

        $res2 = $this->actingAs($admin)->get("/admin/review-results?review_window_id={$window->id}&section_id={$section->id}");
        $res2->assertSuccessful();
        $res2->assertSee('5 responses');
    }
}
