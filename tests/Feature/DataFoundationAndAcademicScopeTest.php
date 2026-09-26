<?php

namespace Tests\Feature;

use App\Enums\AuditAssignmentStatus;
use App\Enums\FormType;
use App\Enums\QuestionType;
use App\Enums\ReviewWindowStatus;
use App\Enums\UserRole;
use App\Models\AuditAssignment;
use App\Models\AuditProvenanceLog;
use App\Models\Course;
use App\Models\Department;
use App\Models\FacultyAssignment;
use App\Models\FormVersion;
use App\Models\Question;
use App\Models\ReviewParticipation;
use App\Models\ReviewResponse;
use App\Models\ReviewWindow;
use App\Models\Section;
use App\Models\StudentEnrollment;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class DataFoundationAndAcademicScopeTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;
    protected User $auditor;
    protected User $auditee;
    protected User $student;
    protected Section $sectionFall;
    protected Section $sectionSpring;
    protected ReviewWindow $activeWindow;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);

        $this->admin = User::where('role', UserRole::Admin)->first();
        $faculty = User::where('role', UserRole::Faculty)->get();
        $this->auditor = $faculty[0];
        $this->auditee = $faculty[1];
        $this->student = User::where('role', UserRole::Student)->first();

        $this->sectionFall = Section::where('term', 'Fall 2026')->first();

        // Create an out-of-scope spring section
        $course = Course::first();
        $this->sectionSpring = Section::create([
            'course_id' => $course->id,
            'name' => 'Section S1',
            'term' => 'Spring 2027',
            'is_active' => true,
        ]);

        // Enroll student in both
        StudentEnrollment::firstOrCreate([
            'section_id' => $this->sectionFall->id,
            'student_id' => $this->student->id,
        ]);
        StudentEnrollment::create([
            'section_id' => $this->sectionSpring->id,
            'student_id' => $this->student->id,
        ]);

        $this->activeWindow = ReviewWindow::where('status', ReviewWindowStatus::Active)->first();
        $this->activeWindow->term = 'Fall 2026';
        $this->activeWindow->save();
    }

    private function studentToken(): string
    {
        return $this->student->createToken('student_test')->plainTextToken;
    }

    private function adminToken(): string
    {
        return $this->admin->createToken('admin_test')->plainTextToken;
    }

    public function test_student_cannot_review_out_of_scope_term_section(): void
    {
        $token = $this->studentToken();

        // Attempting to fetch review form for spring section in fall window fails
        $response = $this->withToken($token)
            ->getJson("/api/student/review-form?review_window_id={$this->activeWindow->id}&section_id={$this->sectionSpring->id}");

        $response->assertForbidden()
            ->assertJson(['message' => 'Forbidden. This course section is not within the academic scope of this review cycle.']);
    }

    public function test_student_cannot_submit_for_out_of_scope_section(): void
    {
        $token = $this->studentToken();

        $activeQuestions = Question::where('form_type', FormType::StudentReview)
            ->where('is_active', true)
            ->get();

        $answersPayload = [];
        foreach ($activeQuestions as $q) {
            $answersPayload[] = [
                'question_id' => $q->id,
                'value' => match ($q->question_type) {
                    QuestionType::Rating => 5,
                    QuestionType::YesNo => true,
                    default => 'Feedback text',
                },
            ];
        }

        $response = $this->withToken($token)
            ->postJson('/api/student/reviews', [
                'review_window_id' => $this->activeWindow->id,
                'section_id' => $this->sectionSpring->id,
                'answers' => $answersPayload,
            ]);

        $response->assertForbidden()
            ->assertJson(['message' => 'Forbidden. You are not eligible to review this section in this cycle.']);
    }

    public function test_frozen_roster_snapshot_prevents_subsequent_unenrolled_student(): void
    {
        // Snapshot roster for active window
        $this->activeWindow->snapshotRoster();

        // Create a new student not in frozen roster
        $lateStudent = User::create([
            'name' => 'Late Enrollee',
            'email' => 'late@fasre.test',
            'password' => 'Password@123',
            'role' => UserRole::Student,
            'is_active' => true,
        ]);
        $lateToken = $lateStudent->createToken('late_test')->plainTextToken;

        // Even if student enrollment is created after roster freeze without authorized addition
        StudentEnrollment::create([
            'section_id' => $this->sectionFall->id,
            'student_id' => $lateStudent->id,
        ]);

        $response = $this->withToken($lateToken)
            ->getJson("/api/student/review-form?review_window_id={$this->activeWindow->id}&section_id={$this->sectionFall->id}");

        $response->assertForbidden()
            ->assertJson(['message' => 'Forbidden. You are not eligible to review this section in this cycle.']);
    }

    public function test_cannot_assign_audit_if_auditee_does_not_teach_section(): void
    {
        $unassignedSection = Section::create([
            'course_id' => Course::first()->id,
            'name' => 'Section Z',
            'term' => 'Fall 2026',
            'is_active' => true,
        ]);

        $response = $this->withToken($this->adminToken())
            ->postJson('/api/admin/audit-assignments', [
                'auditor_id' => $this->auditor->id,
                'auditee_id' => $this->auditee->id,
                'section_id' => $unassignedSection->id,
                'due_date' => now()->addDays(7)->toDateString(),
            ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['section_id']);
    }

    public function test_allows_separate_audit_observations_for_different_sections(): void
    {
        $faculty = User::where('role', UserRole::Faculty)->get();
        $freshAuditor = $faculty[2]; // Usman Raza (no active audit assignments in seed data)

        // Auditee teaches section 2 in seed data
        $auditeeAssignment = FacultyAssignment::where('faculty_id', $this->auditee->id)->first();
        $section1 = $auditeeAssignment->section;

        // Create a second section taught by the same auditee
        $section2 = Section::create([
            'course_id' => Course::first()->id,
            'name' => 'Section Lab 2',
            'term' => 'Fall 2026',
            'is_active' => true,
        ]);
        FacultyAssignment::create([
            'section_id' => $section2->id,
            'faculty_id' => $this->auditee->id,
            'is_primary' => true,
        ]);

        // Assign auditor to observe Section 1
        $res1 = $this->withToken($this->adminToken())
            ->postJson('/api/admin/audit-assignments', [
                'auditor_id' => $freshAuditor->id,
                'auditee_id' => $this->auditee->id,
                'section_id' => $section1->id,
                'due_date' => now()->addDays(7)->toDateString(),
            ]);
        $res1->assertCreated();

        // Assign same auditor to observe Section 2 — should succeed!
        $res2 = $this->withToken($this->adminToken())
            ->postJson('/api/admin/audit-assignments', [
                'auditor_id' => $freshAuditor->id,
                'auditee_id' => $this->auditee->id,
                'section_id' => $section2->id,
                'due_date' => now()->addDays(14)->toDateString(),
            ]);
        $res2->assertCreated();

        // But duplicate assignment for the SAME section should be rejected
        $res3 = $this->withToken($this->adminToken())
            ->postJson('/api/admin/audit-assignments', [
                'auditor_id' => $freshAuditor->id,
                'auditee_id' => $this->auditee->id,
                'section_id' => $section1->id,
                'due_date' => now()->addDays(21)->toDateString(),
            ]);
        $res3->assertUnprocessable()
            ->assertJsonValidationErrors(['auditee_id']);
    }

    public function test_immutable_form_version_serves_frozen_questions_after_question_bank_edit(): void
    {
        // 1. Create a frozen form version
        $formVersion = FormVersion::create([
            'form_type' => 'student_review',
            'version_code' => 'v2.0-test',
            'title' => 'Frozen Fall 2026 Survey',
            'questions_json' => [
                [
                    'id' => 999,
                    'question_text' => 'ORIGINAL FROZEN QUESTION TEXT',
                    'question_type' => 'rating',
                    'is_required' => true,
                    'sort_order' => 1,
                ],
            ],
            'is_published' => true,
        ]);

        $this->activeWindow->form_version_id = $formVersion->id;
        $this->activeWindow->save();

        // 2. Modify or delete questions in the mutable question bank
        Question::where('form_type', FormType::StudentReview)->delete();

        // 3. Requesting review-form still serves the frozen question definition
        $token = $this->studentToken();
        $response = $this->withToken($token)
            ->getJson("/api/student/review-form?review_window_id={$this->activeWindow->id}&section_id={$this->sectionFall->id}");

        $response->assertOk();
        $questions = $response->json('data.questions');
        $this->assertCount(1, $questions);
        $this->assertEquals(999, $questions[0]['id']);
        $this->assertEquals('ORIGINAL FROZEN QUESTION TEXT', $questions[0]['question_text']);
    }

    public function test_section_deletion_soft_deletes_and_protects_historical_evaluations(): void
    {
        $section = $this->sectionFall;

        // Verify section has evaluations from seed data
        $this->assertTrue($section->hasEvaluations());

        // Perform delete via SectionController
        $response = $this->withToken($this->adminToken())
            ->deleteJson("/api/admin/sections/{$section->id}");

        $response->assertOk()
            ->assertJson(['message' => 'Section archived. Historical evaluation and audit records have been preserved.']);

        // Verify section is soft-deleted, NOT hard-deleted
        $this->assertSoftDeleted('sections', ['id' => $section->id]);

        // Historical review responses and participations remain intact!
        $this->assertDatabaseHas('review_responses', ['section_id' => $section->id]);
        $this->assertDatabaseHas('review_participations', ['section_id' => $section->id]);

        // Verify provenance log entry
        $this->assertDatabaseHas('audit_provenance_logs', [
            'auditable_type' => Section::class,
            'auditable_id' => $section->id,
            'action' => 'archived_with_evaluations',
        ]);
    }

    public function test_bulk_import_dry_run_previews_without_inserting_rows(): void
    {
        $csv = "course_code,name,term\nCS101,Section Preview,Fall 2026\nCS101,Section Preview 2,Fall 2026\n";

        $response = $this->withToken($this->adminToken())
            ->postJson('/api/admin/bulk-import', [
                'type' => 'sections',
                'csv' => $csv,
                'dry_run' => true,
            ]);

        $response->assertOk()
            ->assertJsonPath('data.dry_run', true)
            ->assertJsonPath('data.valid_count', 2);

        // Verify no rows were actually committed to database
        $this->assertDatabaseMissing('sections', ['name' => 'Section Preview']);
        $this->assertDatabaseMissing('sections', ['name' => 'Section Preview 2']);
    }
}
