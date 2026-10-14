<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\Department;
use App\Models\Question;
use App\Models\ReviewWindow;
use App\Models\Section;
use App\Models\StudentEnrollment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ReviewWindowIntegrityRegressionTest extends TestCase
{
    use RefreshDatabase;

    private Department $department;
    private User $admin;
    private ReviewWindow $window;
    private Section $section;

    protected function setUp(): void
    {
        parent::setUp();
        $this->department = Department::create(['name' => 'Computing', 'code' => 'CS']);
        $this->admin = User::factory()->create(['role' => 'admin', 'department_id' => $this->department->id, 'is_central_qa' => false]);
        $course = Course::create(['department_id' => $this->department->id, 'code' => 'CS101', 'title' => 'Computing']);
        $this->section = Section::create(['course_id' => $course->id, 'name' => 'A', 'term' => 'Fall 2026', 'is_active' => true]);
        $this->window = ReviewWindow::create([
            'title' => 'Integrity test', 'department_id' => $this->department->id, 'term' => 'Fall 2026',
            'starts_at' => now()->subHour(), 'ends_at' => now()->addDay(), 'status' => 'draft',
        ]);
        Sanctum::actingAs($this->admin);
    }

    public function test_department_admin_cannot_remove_window_department(): void
    {
        $this->patchJson("/api/admin/review-windows/{$this->window->id}", ['department_id' => null])->assertForbidden();
        $this->assertSame($this->department->id, $this->window->fresh()->department_id);
    }

    public function test_start_only_update_cannot_move_past_existing_end(): void
    {
        $this->patchJson("/api/admin/review-windows/{$this->window->id}", ['starts_at' => now()->addDays(2)->toIso8601String()])
            ->assertUnprocessable()->assertJsonValidationErrors('ends_at');
    }

    public function test_end_only_update_uses_existing_start(): void
    {
        $this->patchJson("/api/admin/review-windows/{$this->window->id}", ['ends_at' => now()->addDays(2)->toIso8601String()])->assertOk();
    }

    public function test_end_only_update_cannot_precede_existing_start(): void
    {
        $this->patchJson("/api/admin/review-windows/{$this->window->id}", ['ends_at' => now()->subDays(2)->toIso8601String()])
            ->assertUnprocessable()->assertJsonValidationErrors('ends_at');
    }

    public function test_term_change_revalidates_retained_explicit_sections(): void
    {
        $this->window->sections()->attach($this->section);
        $this->patchJson("/api/admin/review-windows/{$this->window->id}", ['term' => 'Spring 2027'])
            ->assertUnprocessable()->assertJsonValidationErrors('section_ids');
    }

    public function test_department_change_revalidates_retained_explicit_sections(): void
    {
        $central = User::factory()->create(['role' => 'admin', 'department_id' => null, 'is_central_qa' => true]);
        Sanctum::actingAs($central);
        $other = Department::create(['name' => 'Engineering', 'code' => 'ENG']);
        $this->window->sections()->attach($this->section);
        $this->patchJson("/api/admin/review-windows/{$this->window->id}", ['department_id' => $other->id])
            ->assertUnprocessable()->assertJsonValidationErrors('section_ids');
    }

    public function test_empty_frozen_roster_does_not_admit_late_enrollments(): void
    {
        Question::create(['form_type' => 'student_review', 'question_type' => 'rating', 'question_text' => 'Teaching quality', 'is_active' => true, 'is_required' => true]);
        $this->postJson("/api/admin/review-windows/{$this->window->id}/activate")->assertOk();
        $student = User::factory()->create(['role' => 'student']);
        StudentEnrollment::create(['student_id' => $student->id, 'section_id' => $this->section->id]);
        $this->assertFalse($this->window->fresh()->isStudentEligible($student->id, $this->section->id));
        Sanctum::actingAs($student);
        $this->getJson("/api/student/review-form?section_id={$this->section->id}&review_window_id={$this->window->id}")->assertForbidden();
    }

    public function test_empty_snapshot_remains_frozen_on_repeat_snapshot(): void
    {
        $this->assertSame(0, $this->window->snapshotRoster());
        $student = User::factory()->create(['role' => 'student']);
        StudentEnrollment::create(['student_id' => $student->id, 'section_id' => $this->section->id]);
        $this->assertSame(0, $this->window->fresh()->snapshotRoster());
        $this->assertFalse($this->window->fresh()->isStudentEligible($student->id, $this->section->id));
    }
}
