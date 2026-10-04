<?php

namespace Tests\Feature;

use App\Models\AuditAssignment;
use App\Models\Course;
use App\Models\Department;
use App\Models\FormVersion;
use App\Models\Question;
use App\Models\ReviewParticipation;
use App\Models\ReviewWindow;
use App\Models\Section;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CompleteWorkflowVerificationTest extends TestCase
{
    use RefreshDatabase;

    private AuditAssignment $audit;
    private User $auditor;
    private User $auditee;

    public function test_locked_results_still_enforce_department_scope(): void
    {
        $foreign = Department::create(['code' => 'OTHER', 'name' => 'Other department']);
        $admin = User::factory()->create(['role' => 'admin', 'department_id' => $this->auditee->department_id, 'is_central_qa' => false]);
        Sanctum::actingAs($admin);
        foreach (['draft', 'active'] as $status) {
            $window = ReviewWindow::create(['department_id' => $foreign->id, 'title' => 'Private cycle', 'starts_at' => now(), 'ends_at' => now()->addDay(), 'status' => $status]);
            $this->getJson("/api/admin/review-results?review_window_id={$window->id}")->assertForbidden();
        }
    }

    protected function setUp(): void
    {
        parent::setUp();
        $dept = Department::create(['code' => 'CS', 'name' => 'Computer Science']);
        $course = Course::create(['department_id' => $dept->id, 'code' => 'CS101', 'title' => 'Computing', 'credit_hours' => 3, 'is_active' => true]);
        $section = Section::create(['course_id' => $course->id, 'name' => 'A', 'term' => 'Fall 2026', 'is_active' => true]);
        $this->auditor = User::factory()->create(['role' => 'faculty', 'department_id' => $dept->id]);
        $this->auditee = User::factory()->create(['role' => 'faculty', 'department_id' => $dept->id]);
        $admin = User::factory()->create(['role' => 'admin']);
        $version = FormVersion::create(['form_type' => 'faculty_audit', 'version_code' => 'VERIFY-1', 'title' => 'Verification rubric', 'is_published' => true, 'created_by' => $admin->id, 'questions_json' => [
            ['id' => 1, 'question_text' => 'Observation', 'question_type' => 'text', 'is_required' => false],
        ]]);
        $this->audit = AuditAssignment::create(['auditor_id' => $this->auditor->id, 'auditee_id' => $this->auditee->id, 'section_id' => $section->id, 'form_version_id' => $version->id, 'assigned_by' => $admin->id, 'status' => 'assigned']);
        Sanctum::actingAs($this->auditor);
    }

    public function test_optional_value_can_be_omitted_in_a_draft_and_submission(): void
    {
        $payload = ['answers' => [['question_id' => 1]]];
        $this->postJson("/api/faculty/audits/{$this->audit->id}/save-draft", $payload)->assertOk()->assertJsonPath('data.answers_json.1', null);
        $this->postJson("/api/faculty/audits/{$this->audit->id}/submit", $payload)->assertOk();
    }

    public function test_required_text_cannot_be_satisfied_with_whitespace(): void
    {
        $this->audit->formVersion->update(['questions_json' => [['id' => 1, 'question_text' => 'Required finding', 'question_type' => 'text', 'is_required' => true]]]);
        $this->postJson("/api/faculty/audits/{$this->audit->id}/submit", ['answers' => [['question_id' => 1, 'value' => '   ']]])->assertUnprocessable();
    }

    public function test_invalid_criterion_comment_is_rejected_without_a_server_error(): void
    {
        foreach (['save-draft', 'submit'] as $operation) {
            $this->postJson("/api/faculty/audits/{$this->audit->id}/{$operation}", ['answers' => [['question_id' => 1, 'value' => 'Finding', 'comment' => ['unexpected']]]])->assertUnprocessable();
        }
    }

    public function test_audit_notifications_respect_department_scope(): void
    {
        $other = Department::create(['code' => 'BUS', 'name' => 'Business']);
        $foreign = User::factory()->create(['role' => 'admin', 'department_id' => $other->id, 'is_central_qa' => false]);
        $local = User::factory()->create(['role' => 'admin', 'department_id' => $this->auditee->department_id, 'is_central_qa' => false]);
        $orphan = User::factory()->create(['role' => 'admin', 'is_central_qa' => false]);
        $this->postJson("/api/faculty/audits/{$this->audit->id}/submit", ['answers' => [['question_id' => 1, 'value' => 'Finding']]])->assertOk();
        $this->assertDatabaseHas('notifications', ['user_id' => $local->id, 'title' => 'Audit submitted for review']);
        $this->assertDatabaseMissing('notifications', ['user_id' => $foreign->id]);
        $this->assertDatabaseMissing('notifications', ['user_id' => $orphan->id]);
        $this->audit->update(['status' => 'approved']);
        Sanctum::actingAs($this->auditee);
        $this->postJson("/api/faculty/my-reports/{$this->audit->id}/response", ['response_text' => 'Acknowledged'])->assertOk();
        $this->assertDatabaseMissing('notifications', ['user_id' => $foreign->id]);
        $this->assertDatabaseMissing('notifications', ['user_id' => $orphan->id]);
    }

    public function test_auditee_can_read_approved_reports_through_all_later_states(): void
    {
        Sanctum::actingAs($this->auditee);
        foreach (['approved', 'faculty_responded', 'action_plan_active', 'closed'] as $status) {
            $this->audit->update(['status' => $status]);
            $this->getJson("/api/faculty/audits/{$this->audit->id}")->assertOk();
            $this->getJson("/api/faculty/audit-form?audit_id={$this->audit->id}")->assertOk();
        }
    }

    public function test_assignment_form_never_falls_back_for_missing_or_unauthorized_audits(): void
    {
        $this->audit->update(['form_version_id' => null]);
        Sanctum::actingAs(User::factory()->create(['role' => 'faculty']));
        $this->getJson("/api/faculty/audit-form?audit_id={$this->audit->id}")->assertForbidden();
        $this->getJson('/api/faculty/audit-form?audit_id=999999')->assertNotFound();
    }

    public function test_student_receipts_survive_cycle_closure_and_exclude_other_students(): void
    {
        $student = User::factory()->create();
        $window = ReviewWindow::create(['title' => 'Closed cycle', 'term' => 'Fall 2026', 'starts_at' => now()->subDays(10), 'ends_at' => now()->subDay(), 'status' => 'closed']);
        ReviewParticipation::create(['student_id' => $student->id, 'review_window_id' => $window->id, 'section_id' => $this->audit->section_id, 'submitted_at' => now()]);
        ReviewParticipation::create(['student_id' => User::factory()->create()->id, 'review_window_id' => $window->id, 'section_id' => $this->audit->section_id, 'submitted_at' => now()]);
        Sanctum::actingAs($student);
        $this->getJson('/api/student/submissions')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.review_window_id', $window->id)->assertJsonMissingPath('data.0.student_id')->assertJsonMissingPath('data.0.answers_json')->assertJsonMissingPath('data.0.submitted_at');
    }

    public function test_last_action_closure_records_audit_closure_attribution(): void
    {
        $admin = User::findOrFail($this->audit->assigned_by);
        Sanctum::actingAs($admin);
        $this->audit->update(['status' => 'approved']);
        $action = $this->postJson("/api/admin/audit-assignments/{$this->audit->id}/actions", ['finding' => 'Finding', 'agreed_action' => 'Follow up', 'owner_id' => $this->auditee->id, 'due_date' => now()->addWeek()->toDateString()])->assertCreated()->json('data.id');
        $this->patchJson("/api/admin/audit-assignments/{$this->audit->id}/actions/{$action}", ['status' => 'closed'])->assertOk();
        $closed = $this->audit->fresh();
        $this->assertSame($admin->id, $closed->closed_by);
        $this->assertNotNull($closed->closed_at);
    }
}
