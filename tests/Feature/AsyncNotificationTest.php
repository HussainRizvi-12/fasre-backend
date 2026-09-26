<?php

namespace Tests\Feature;

use App\Enums\ReviewWindowStatus;
use App\Enums\UserRole;
use App\Jobs\SendReviewWindowNotificationsJob;
use App\Models\AppNotification;
use App\Models\Course;
use App\Models\Department;
use App\Models\FormVersion;
use App\Models\ReviewWindow;
use App\Models\Section;
use App\Models\StudentEnrollment;
use App\Models\User;
use App\Services\NotificationService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class AsyncNotificationTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;
    protected Department $department;
    protected FormVersion $formVersion;
    protected Section $section;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);

        $this->admin = User::where('role', UserRole::Admin)->first();
        $this->department = Department::first();
        $this->formVersion = FormVersion::create([
            'form_type' => 'student_review',
            'version_code' => 'v1.0-async-test',
            'title' => 'Async Test Survey',
            'questions_json' => [
                [
                    'id' => 1,
                    'question_text' => 'Sample survey question',
                    'question_type' => 'rating',
                    'is_required' => true,
                    'sort_order' => 1,
                ],
            ],
            'is_published' => true,
        ]);

        $course = Course::first();
        $this->section = Section::create([
            'course_id' => $course->id,
            'name' => 'SEC-ASYNC-101',
            'term' => 'Fall 2026',
            'academic_year' => '2026-2027',
            'capacity' => 40,
        ]);
    }

    private function adminToken(): string
    {
        return $this->admin->createToken('admin_test')->plainTextToken;
    }

    public function test_activating_review_window_dispatches_notification_job_asynchronously(): void
    {
        Queue::fake();

        // Close any existing active window to satisfy single-active constraint
        ReviewWindow::where('status', ReviewWindowStatus::Active)
            ->update(['status' => ReviewWindowStatus::Closed]);

        $window = ReviewWindow::create([
            'department_id' => $this->department->id,
            'form_version_id' => $this->formVersion->id,
            'title' => 'Midterm Evaluation 2026',
            'term' => 'Fall 2026',
            'academic_year' => '2026-2027',
            'starts_at' => now(),
            'ends_at' => now()->addDays(14),
            'status' => ReviewWindowStatus::Draft,
            'allow_partial_save' => true,
        ]);
        $window->sections()->attach($this->section->id);

        $startTime = microtime(true);

        $response = $this->withToken($this->adminToken())
            ->postJson("/api/admin/review-windows/{$window->id}/activate");

        $duration = microtime(true) - $startTime;

        $response->assertOk()
            ->assertJsonPath('data.status', 'active');

        // Activation HTTP response should be fast (sub-second)
        $this->assertLessThan(2.0, $duration, 'Window activation endpoint should return promptly without synchronous fan-out blocking.');

        Queue::assertPushed(SendReviewWindowNotificationsJob::class, function (SendReviewWindowNotificationsJob $job) use ($window) {
            return $job->reviewWindow->id === $window->id;
        });
    }

    public function test_job_bulk_inserts_notifications_for_rostered_students(): void
    {
        // Close other windows
        ReviewWindow::where('status', ReviewWindowStatus::Active)
            ->update(['status' => ReviewWindowStatus::Closed]);

        $window = ReviewWindow::create([
            'department_id' => $this->department->id,
            'form_version_id' => $this->formVersion->id,
            'title' => 'Rostered Window',
            'term' => 'Fall 2026',
            'academic_year' => '2026-2027',
            'starts_at' => now(),
            'ends_at' => now()->addDays(7),
            'status' => ReviewWindowStatus::Active,
        ]);
        $window->sections()->attach($this->section->id);

        // Create 3 students enrolled in this section
        $students = [];
        for ($i = 1; $i <= 3; $i++) {
            $student = User::create([
                'name' => "Roster Student {$i}",
                'email' => "roster_student_{$i}@test.com",
                'password' => bcrypt('password'),
                'role' => UserRole::Student,
                'department_id' => $this->department->id,
                'is_active' => true,
            ]);
            StudentEnrollment::create([
                'student_id' => $student->id,
                'section_id' => $this->section->id,
                'status' => 'enrolled',
            ]);
            $students[] = $student;
        }

        // Snapshot roster
        $rosterCount = $window->snapshotRoster();
        $this->assertGreaterThanOrEqual(3, $rosterCount);

        // Execute job synchronously
        $job = new SendReviewWindowNotificationsJob($window);
        $job->handle();

        // Check that notifications were inserted for these students
        foreach ($students as $student) {
            $this->assertDatabaseHas('notifications', [
                'user_id' => $student->id,
                'type' => 'window',
                'title' => 'Review window is now open',
            ]);
        }
    }

    public function test_job_falls_back_to_all_active_students_if_roster_is_empty(): void
    {
        ReviewWindow::where('status', ReviewWindowStatus::Active)
            ->update(['status' => ReviewWindowStatus::Closed]);

        $window = ReviewWindow::create([
            'department_id' => $this->department->id,
            'form_version_id' => $this->formVersion->id,
            'title' => 'Unrostered Window',
            'term' => 'Fall 2026',
            'academic_year' => '2026-2027',
            'starts_at' => now(),
            'ends_at' => now()->addDays(7),
            'status' => ReviewWindowStatus::Active,
        ]);

        $activeStudentCount = User::where('role', UserRole::Student)->where('is_active', true)->count();
        $this->assertGreaterThan(0, $activeStudentCount);

        // Clear existing notifications
        AppNotification::query()->delete();

        $job = new SendReviewWindowNotificationsJob($window);
        $job->handle();

        $notificationCount = AppNotification::where('type', 'window')->count();
        $this->assertEquals($activeStudentCount, $notificationCount);
    }

    public function test_bulk_insert_in_app_service(): void
    {
        $students = User::where('role', UserRole::Student)->limit(5)->pluck('id');

        $inserted = NotificationService::bulkInsertInApp(
            $students,
            'test_type',
            'Test Notice Title',
            'Test Notice Body',
            ['key' => 'val'],
            chunkSize: 2
        );

        $this->assertEquals($students->count(), $inserted);
        $this->assertDatabaseHas('notifications', [
            'type' => 'test_type',
            'title' => 'Test Notice Title',
        ]);
    }
}
