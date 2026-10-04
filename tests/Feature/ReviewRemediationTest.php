<?php

namespace Tests\Feature;

use App\Enums\AuditAssignmentStatus;
use App\Models\AuditAssignment;
use App\Models\Course;
use App\Models\Department;
use App\Models\FormVersion;
use App\Models\Question;
use App\Models\ReviewResponse;
use App\Models\ReviewWindow;
use App\Models\Section;
use App\Models\StudentEnrollment;
use App\Models\User;
use App\Services\AuditScoringService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ReviewRemediationTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private User $student;
    private User $auditor;
    private User $auditee;
    private Section $section;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create(['role' => 'admin', 'department_id' => null]);
        $this->student = User::factory()->create(['role' => 'student']);
        $this->auditor = User::factory()->create(['role' => 'faculty']);
        $this->auditee = User::factory()->create(['role' => 'faculty']);
        $dept = Department::create(['code' => 'CS', 'name' => 'Computing']);
        $course = Course::create(['department_id' => $dept->id, 'code' => 'CS101', 'title' => 'Computing']);
        $this->section = Section::create(['course_id' => $course->id, 'name' => 'A', 'term' => 'Spring 2027', 'is_active' => true]);
        StudentEnrollment::create(['section_id' => $this->section->id, 'student_id' => $this->student->id]);
    }

    private function audit(string $status): AuditAssignment
    {
        return AuditAssignment::create([
            'auditor_id' => $this->auditor->id, 'auditee_id' => $this->auditee->id,
            'assigned_by' => $this->admin->id, 'section_id' => $this->section->id,
            'status' => $status, 'due_date' => now()->addDays(7),
        ]);
    }

    private function actionBody(): array
    {
        return ['finding' => 'Pacing', 'agreed_action' => 'Add practice',
            'owner_id' => $this->auditee->id, 'due_date' => now()->addDays(7)->toDateString()];
    }

    public function test_action_creation_rejects_invalid_lifecycle_states(): void
    {
        $this->actingAs($this->admin, 'sanctum');
        foreach (['assigned', 'in_progress', 'submitted', 'rejected', 'closed'] as $status) {
            $audit = $this->audit($status);
            $this->postJson("/api/admin/audit-assignments/{$audit->id}/actions", $this->actionBody())->assertUnprocessable();
            $this->assertSame(0, $audit->improvementActions()->count());
        }
    }

    public function test_both_action_endpoints_clear_notes_and_closed_audits_are_immutable(): void
    {
        $audit = $this->audit('approved');
        $this->actingAs($this->admin, 'sanctum');
        $this->postJson("/api/admin/audit-assignments/{$audit->id}/actions", $this->actionBody())->assertCreated();
        $action = $audit->improvementActions()->firstOrFail();
        $action->update(['follow_up_note' => 'Old note']);
        $this->patchJson("/api/admin/audit-assignments/{$audit->id}/actions/{$action->id}", ['follow_up_note' => null])->assertOk();
        $this->assertNull($action->fresh()->follow_up_note);
        $action->update(['follow_up_note' => 'Another note']);
        $this->actingAs($this->auditee, 'sanctum');
        $this->patchJson("/api/faculty/my-reports/{$audit->id}/actions/{$action->id}", ['follow_up_note' => null])->assertOk();
        $this->assertNull($action->fresh()->follow_up_note);
        $audit->update(['status' => AuditAssignmentStatus::Closed]);
        $this->patchJson("/api/faculty/my-reports/{$audit->id}/actions/{$action->id}", ['status' => 'open'])->assertUnprocessable();
        $this->actingAs($this->admin, 'sanctum');
        $this->patchJson("/api/admin/audit-assignments/{$audit->id}/actions/{$action->id}", ['status' => 'open'])->assertUnprocessable();
    }

    public function test_consent_is_persisted_and_term_comes_from_enrollment(): void
    {
        $this->actingAs($this->student, 'sanctum');
        $this->getJson('/api/me')->assertJsonPath('user.has_consented', false)->assertJsonPath('user.enrolled_term', 'Spring 2027');
        $this->postJson('/api/student/consent', ['accepted' => true, 'version' => 'student-review-v1'])->assertOk()->assertJsonPath('data.has_consented', true);
        $this->assertNotNull($this->student->fresh()->consented_at);
        $this->postJson('/api/student/consent', ['accepted' => true, 'version' => 'wrong-version'])->assertUnprocessable();
        $this->postJson('/api/student/consent', ['accepted' => false, 'version' => 'student-review-v1'])->assertOk()->assertJsonPath('data.has_consented', false);
    }

    public function test_existing_sequential_responses_are_rekeyed_without_losing_answers(): void
    {
        $window = ReviewWindow::create(['title' => 'Historical', 'starts_at' => now()->subDays(3), 'ends_at' => now()->subDay(), 'status' => 'closed']);
        foreach ([1, 2, 3] as $id) {
            DB::table('review_responses')->insert(['id' => $id, 'review_window_id' => $window->id,
                'section_id' => $this->section->id, 'pseudonym_token' => 'token-'.$id,
                'answers_json' => json_encode(['1' => $id]), 'submitted_at' => now()->startOfDay()]);
        }
        $migration = require database_path('migrations/2026_10_03_000001_randomize_existing_review_response_ids.php');
        $migration->up();
        $this->assertSame(3, ReviewResponse::count());
        foreach ([1, 2, 3] as $id) {
            $row = ReviewResponse::where('pseudonym_token', 'token-'.$id)->firstOrFail();
            $this->assertGreaterThan(3, $row->id);
            $this->assertSame(['1' => $id], $row->answers_json);
        }
        $new = ReviewResponse::create(['review_window_id' => $window->id, 'section_id' => $this->section->id,
            'pseudonym_token' => 'new', 'answers_json' => ['1' => 5], 'submitted_at' => now()->startOfDay()]);
        $this->assertNotSame(4, $new->id);
        $this->assertSame(4, ReviewResponse::count());
    }

    public function test_published_notifications_only_reach_the_cycle_roster(): void
    {
        $outsider = User::factory()->create(['role' => 'student']);
        $window = ReviewWindow::create(['title' => 'Scoped cycle', 'term' => 'Spring 2027', 'starts_at' => now()->subDays(3), 'ends_at' => now()->subDay(), 'status' => 'closed']);
        $window->snapshotRoster();
        $this->actingAs($this->admin, 'sanctum')->postJson("/api/admin/review-windows/{$window->id}/publish-results")->assertOk();
        $this->assertDatabaseHas('notifications', ['user_id' => $this->student->id, 'type' => 'result']);
        $this->assertDatabaseMissing('notifications', ['user_id' => $outsider->id]);
    }

    public function test_new_form_versions_use_the_same_default_scoring_bands(): void
    {
        Question::create(['form_type' => 'faculty_audit', 'question_text' => 'Pacing', 'question_type' => 'rating', 'is_active' => true, 'is_required' => true, 'sort_order' => 1]);
        $this->actingAs($this->admin, 'sanctum')->postJson('/api/admin/form-versions', ['form_type' => 'faculty_audit', 'version_code' => 'v-next', 'title' => 'New rubric'])->assertCreated();
        $version = FormVersion::where('version_code', 'v-next')->firstOrFail();
        $this->assertEquals(AuditScoringService::DEFAULT_BANDS, $version->scoring_rules_json['bands']);
    }

    public function test_revoked_cookie_without_csrf_does_not_block_fresh_login(): void
    {
        $token = $this->admin->createToken('portal-session');
        $cookie = Crypt::encryptString($token->plainTextToken);
        $token->accessToken->delete();
        $this->withCredentials()->withUnencryptedCookie('fasre_session', $cookie)->postJson('/api/login', [
            'email' => $this->admin->email, 'password' => 'password',
        ])->assertOk();
    }

    public function test_null_department_is_not_a_central_grant_and_orphan_admin_is_denied(): void
    {
        $delegated = User::factory()->create(['role' => 'admin', 'department_id' => $this->section->course->department_id, 'is_central_qa' => false]);
        $delegated->update(['department_id' => null]);
        $this->assertFalse($delegated->fresh()->isCentralQa());
        $this->actingAs($delegated->fresh(), 'sanctum')->getJson('/api/admin/departments')->assertForbidden();
    }

    public function test_only_explicit_authorized_grants_create_central_accounts(): void
    {
        $body = ['name' => 'New administrator', 'email' => 'newadmin@example.test', 'password' => 'password', 'role' => 'admin'];
        $this->actingAs($this->admin, 'sanctum')->postJson('/api/admin/users', $body)->assertUnprocessable();
        $this->postJson('/api/admin/users', [...$body, 'is_central_qa' => true])->assertCreated()->assertJsonPath('data.is_central_qa', true);
        $delegated = User::factory()->create(['role' => 'admin', 'department_id' => $this->section->course->department_id, 'is_central_qa' => false]);
        $this->actingAs($delegated, 'sanctum')->postJson('/api/admin/users', [...$body, 'email' => 'another@example.test', 'is_central_qa' => true])->assertForbidden();
    }

    public function test_unversioned_historical_answers_prevent_question_reinterpretation(): void
    {
        $question = Question::create(['form_type' => 'student_review', 'question_text' => 'Original wording',
            'question_type' => 'rating', 'is_active' => true, 'is_required' => true, 'sort_order' => 1]);
        $window = ReviewWindow::create(['title' => 'Historical', 'starts_at' => now()->subDays(3),
            'ends_at' => now()->subDay(), 'status' => 'closed']);
        ReviewResponse::create(['review_window_id' => $window->id, 'section_id' => $this->section->id,
            'pseudonym_token' => 'historical', 'answers_json' => [(string) $question->id => 4],
            'submitted_at' => now()->startOfDay()]);
        $this->actingAs($this->admin, 'sanctum')->putJson("/api/admin/questions/{$question->id}", [
            'question_text' => 'Changed meaning', 'question_type' => 'yes_no',
        ])->assertUnprocessable();
        $this->assertSame('Original wording', $question->fresh()->question_text);
        $this->assertSame('rating', $question->fresh()->question_type->value);
    }

    public function test_cycle_activation_freezes_questions_without_an_existing_published_form(): void
    {
        $question = Question::create(['form_type' => 'student_review', 'question_text' => 'Frozen wording',
            'question_type' => 'rating', 'is_active' => true, 'is_required' => true, 'sort_order' => 1]);
        $window = ReviewWindow::create(['title' => 'Next cycle', 'starts_at' => now()->subMinute(),
            'ends_at' => now()->addDay(), 'status' => 'draft']);
        $this->actingAs($this->admin, 'sanctum')->postJson("/api/admin/review-windows/{$window->id}/activate")->assertOk();
        $frozen = $window->fresh()->formVersion;
        $this->assertNotNull($frozen);
        $question->update(['question_text' => 'Later bank wording']);
        $this->assertSame('Frozen wording', $frozen->findQuestion($question->id)['question_text']);
    }

    public function test_stale_cookie_login_still_rejects_disallowed_origins(): void
    {
        $token = $this->admin->createToken('portal-session');
        $cookie = Crypt::encryptString($token->plainTextToken);
        $token->accessToken->delete();
        $this->withCredentials()->withUnencryptedCookie('fasre_session', $cookie)
            ->withHeader('Origin', 'https://untrusted.example.test')->postJson('/api/login', [
                'email' => $this->admin->email, 'password' => 'password',
            ])->assertForbidden();
    }
}
