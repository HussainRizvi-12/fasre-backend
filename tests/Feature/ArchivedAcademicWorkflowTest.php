<?php

namespace Tests\Feature;

use App\Models\AuditAssignment;
use App\Models\Course;
use App\Models\Department;
use App\Models\FacultyAssignment;
use App\Models\FormVersion;
use App\Models\ReviewResponse;
use App\Models\ReviewWindow;
use App\Models\Section;
use App\Models\StudentEnrollment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ArchivedAcademicWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public static function archiveTargets(): array
    {
        return [['section'], ['course']];
    }

    #[DataProvider('archiveTargets')]
    public function test_archived_academic_context_remains_scoped_and_readable(string $target): void
    {
        $department = Department::create(['code' => 'HIST', 'name' => 'Historical department']);
        $other = Department::create(['code' => 'OTHER', 'name' => 'Other department']);
        $course = Course::create(['department_id' => $department->id, 'code' => 'HIST101', 'title' => 'Historical course', 'is_active' => true]);
        $section = Section::create(['course_id' => $course->id, 'name' => 'Historical A', 'term' => 'Fall 2026', 'is_active' => true]);
        $admin = User::factory()->create(['role' => 'admin', 'department_id' => $department->id, 'is_central_qa' => false]);
        $foreign = User::factory()->create(['role' => 'admin', 'department_id' => $other->id, 'is_central_qa' => false]);
        $auditor = User::factory()->create(['role' => 'faculty', 'department_id' => $department->id]);
        $auditee = User::factory()->create(['role' => 'faculty', 'department_id' => $department->id]);
        $student = User::factory()->create(['role' => 'student', 'department_id' => $department->id]);
        FacultyAssignment::create(['section_id' => $section->id, 'faculty_id' => $auditee->id, 'is_primary' => true]);
        StudentEnrollment::create(['section_id' => $section->id, 'student_id' => $student->id]);
        $audit = AuditAssignment::create(['auditor_id' => $auditor->id, 'auditee_id' => $auditee->id, 'section_id' => $section->id, 'assigned_by' => $admin->id, 'status' => 'approved', 'total_score' => 90]);
        ($target === 'section' ? $section : $course)->delete();

        Sanctum::actingAs(User::factory()->create(['role' => 'admin', 'is_central_qa' => true, 'department_id' => null]));
        $this->getJson('/api/admin/sections')->assertOk()->assertJsonCount(0, 'data');
        $this->postJson('/api/admin/faculty-assignments', ['faculty_id' => $auditor->id, 'section_id' => $section->id])->assertUnprocessable()->assertJsonValidationErrors('section_id');
        $newStudent = User::factory()->create(['role' => 'student', 'department_id' => $department->id]);
        $this->postJson('/api/admin/student-enrollments', ['student_id' => $newStudent->id, 'section_id' => $section->id])->assertUnprocessable()->assertJsonValidationErrors('section_id');
        Sanctum::actingAs($admin);
        $this->getJson('/api/admin/sections')->assertOk()->assertJsonCount(0, 'data');
        foreach (['faculty-assignments', 'student-enrollments'] as $entity) {
            $this->getJson('/api/admin/'.$entity)->assertOk()->assertJsonCount(1, 'data')
                ->assertJsonPath('data.0.section.name', 'Historical A')
                ->assertJsonPath('data.0.section.course.code', 'HIST101');
        }
        $this->getJson('/api/admin/audit-assignments')->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.section.name', 'Historical A')->assertJsonPath('data.0.course.code', 'HIST101');
        $this->getJson('/api/admin/audit-assignments/'.$audit->id)->assertOk()->assertJsonPath('data.course.code', 'HIST101');
        foreach (['audit-assignments', 'enrollments'] as $entity) {
            $response = $this->get('/api/admin/export/'.$entity)->assertOk();
            $this->assertStringContainsString('HIST101', $response->streamedContent());
        }

        Sanctum::actingAs($foreign);
        foreach (['faculty-assignments', 'student-enrollments', 'audit-assignments'] as $entity) {
            $this->getJson('/api/admin/'.$entity)->assertOk()->assertJsonCount(0, 'data');
        }
        $this->getJson('/api/admin/audit-assignments/'.$audit->id)->assertForbidden();
        $this->getJson('/api/admin/audit-assignments/'.$audit->id.'/evidence')->assertForbidden();

        Sanctum::actingAs($auditee);
        $this->getJson('/api/faculty/audits/'.$audit->id)->assertOk()->assertJsonPath('data.course.code', 'HIST101');
        $this->getJson('/api/faculty/my-reports')->assertOk()->assertJsonPath('data.0.course_code', 'HIST101');
        Sanctum::actingAs($student);
        $this->getJson('/api/student/enrolled-sections')->assertOk()->assertJsonCount(0, 'data');
        Sanctum::actingAs($admin);
        $this->postJson('/api/admin/audit-assignments', ['auditor_id' => $auditor->id, 'auditee_id' => $auditee->id, 'section_id' => $section->id])->assertUnprocessable();
    }

    #[DataProvider('archiveTargets')]
    public function test_published_aggregates_survive_academic_archiving(string $target): void
    {
        $department = Department::create(['code' => 'HIST', 'name' => 'Historical department']);
        $course = Course::create(['department_id' => $department->id, 'code' => 'HIST101', 'title' => 'Historical course', 'is_active' => true]);
        $section = Section::create(['course_id' => $course->id, 'name' => 'Historical A', 'term' => 'Fall 2026', 'is_active' => true]);
        $admin = User::factory()->create(['role' => 'admin', 'department_id' => $department->id, 'is_central_qa' => false]);
        $student = User::factory()->create(['role' => 'student', 'department_id' => $department->id]);
        StudentEnrollment::create(['section_id' => $section->id, 'student_id' => $student->id]);
        $version = FormVersion::create(['form_type' => 'student_review', 'version_code' => 'HIST-1', 'title' => 'Historical questionnaire', 'is_published' => true, 'created_by' => $admin->id,
            'questions_json' => [['id' => 901, 'question_text' => 'Teaching quality', 'question_type' => 'rating', 'is_required' => true]]]);
        $window = ReviewWindow::create(['title' => 'Historical evaluation', 'term' => 'Fall 2026', 'form_version_id' => $version->id, 'status' => 'published', 'starts_at' => now()->subDays(2), 'ends_at' => now()->subDay()]);
        $window->sections()->attach($section);
        foreach ([1, 2, 3, 4, 5] as $rating) {
            ReviewResponse::create(['review_window_id' => $window->id, 'section_id' => $section->id, 'pseudonym_token' => fake()->uuid(), 'answers_json' => ['901' => $rating], 'submitted_at' => now()->startOfDay()]);
        }
        $section->update(['is_active' => false]);
        ($target === 'section' ? $section : $course)->delete();

        Sanctum::actingAs($admin);
        $this->getJson('/api/admin/review-results?review_window_id='.$window->id)->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.course.code', 'HIST101')->assertJsonPath('data.0.response_count', 5)
            ->assertJsonPath('data.0.is_suppressed', false)->assertJsonPath('data.0.questions.0.average', 3);
        $response = $this->get('/api/admin/export/review-results?review_window_id='.$window->id)->assertOk();
        $this->assertStringContainsString('HIST101', $response->streamedContent());
        $other = Department::create(['code' => 'OTHER', 'name' => 'Other department']);
        Sanctum::actingAs(User::factory()->create(['role' => 'admin', 'department_id' => $other->id, 'is_central_qa' => false]));
        $this->getJson('/api/admin/review-results?review_window_id='.$window->id)->assertForbidden();
        $response = $this->get('/api/admin/export/review-results?review_window_id='.$window->id)->assertOk();
        $this->assertStringNotContainsString('HIST101', $response->streamedContent());
        Sanctum::actingAs($student);
        $this->getJson('/api/student/review-results/published')->assertOk()->assertJsonCount(1, 'data.0.sections')
            ->assertJsonPath('data.0.sections.0.course.code', 'HIST101')->assertJsonPath('data.0.sections.0.questions.0.average', 3);
    }
}
