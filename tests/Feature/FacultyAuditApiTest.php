<?php

namespace Tests\Feature;

use App\Enums\AuditAssignmentStatus;
use App\Enums\FormType;
use App\Enums\QuestionType;
use App\Enums\UserRole;
use App\Models\AuditAssignment;
use App\Models\Question;
use App\Models\Section;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FacultyAuditApiTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;
    protected User $auditor;
    protected User $auditee;
    protected User $student;
    protected AuditAssignment $auditAssignment;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);

        $this->admin = User::where('role', UserRole::Admin)->first();
        $faculty = User::where('role', UserRole::Faculty)->get();
        $this->auditor = $faculty[0];
        $this->auditee = $faculty[1];
        $this->student = User::where('role', UserRole::Student)->first();

        $section = Section::first();

        $this->auditAssignment = AuditAssignment::create([
            'auditor_id' => $this->auditor->id,
            'auditee_id' => $this->auditee->id,
            'section_id' => $section->id,
            'assigned_by' => $this->admin->id,
            'status' => AuditAssignmentStatus::Assigned,
            'due_date' => now()->addDays(7),
        ]);
    }

    public function test_student_cannot_access_faculty_apis(): void
    {
        $studentToken = $this->student->createToken('student_test')->plainTextToken;

        $this->withToken($studentToken)
            ->getJson('/api/faculty/assigned-audits')
            ->assertForbidden()
            ->assertJson(['message' => 'Forbidden. Faculty access required.']);
    }

    public function test_faculty_can_view_assigned_audits(): void
    {
        $token = $this->auditor->createToken('faculty_test')->plainTextToken;

        $response = $this->withToken($token)
            ->getJson('/api/faculty/assigned-audits');

        $response->assertOk()
            ->assertJsonStructure([
                'data' => [
                    '*' => [
                        'id',
                        'auditee' => ['id', 'name', 'email'],
                        'section',
                        'course',
                        'status',
                        'due_date',
                    ],
                ],
            ]);
    }

    public function test_faculty_can_save_audit_draft(): void
    {
        $token = $this->auditor->createToken('faculty_test')->plainTextToken;

        $response = $this->withToken($token)
            ->postJson("/api/faculty/audits/{$this->auditAssignment->id}/save-draft", [
                'answers' => [
                    ['question_id' => 5, 'value' => 4],
                ],
            ]);

        $response->assertOk()
            ->assertJson([
                'message' => 'Audit draft saved successfully.',
                'data' => [
                    'status' => 'in_progress',
                ],
            ]);

        $this->assertEquals(AuditAssignmentStatus::InProgress, $this->auditAssignment->fresh()->status);
    }

    public function test_faculty_can_submit_audit_and_total_score_is_calculated_correctly(): void
    {
        $token = $this->auditor->createToken('faculty_test')->plainTextToken;

        $activeAuditQuestions = Question::where('form_type', FormType::FacultyAudit)
            ->where('is_active', true)
            ->get();

        $answersPayload = [];
        foreach ($activeAuditQuestions as $q) {
            $val = match ($q->question_type) {
                QuestionType::Rating => 4, // 4.0
                QuestionType::YesNo => true, // 5.0
                default => 'Remarks',
            };
            $answersPayload[] = [
                'question_id' => $q->id,
                'value' => $val,
            ];
        }

        $response = $this->withToken($token)
            ->postJson("/api/faculty/audits/{$this->auditAssignment->id}/submit", [
                'answers' => $answersPayload,
            ]);

        $response->assertOk()
            ->assertJsonStructure([
                'message',
                'data' => [
                    'id',
                    'status',
                    'total_score',
                ],
            ]);

        $fresh = $this->auditAssignment->fresh();
        $this->assertEquals(AuditAssignmentStatus::Submitted, $fresh->status);
        $this->assertNotNull($fresh->total_score);
        $this->assertGreaterThan(0, $fresh->total_score);
    }

    public function test_submitted_audit_cannot_be_re_submitted_or_drafted(): void
    {
        $this->auditAssignment->update(['status' => AuditAssignmentStatus::Submitted]);
        $token = $this->auditor->createToken('faculty_test')->plainTextToken;

        $this->withToken($token)
            ->postJson("/api/faculty/audits/{$this->auditAssignment->id}/save-draft", [
                'answers' => [],
            ])
            ->assertUnprocessable();

        $this->withToken($token)
            ->postJson("/api/faculty/audits/{$this->auditAssignment->id}/submit", [
                'answers' => [
                    ['question_id' => 5, 'value' => 5],
                ],
            ])
            ->assertUnprocessable();
    }

    public function test_auditee_can_only_view_their_approved_reports(): void
    {
        // 1. Audit is submitted but not approved yet
        $this->auditAssignment->update([
            'status' => AuditAssignmentStatus::Submitted,
            'total_score' => 85.0,
            'answers_json' => ['5' => 4, '6' => 5],
        ]);

        $auditeeToken = $this->auditee->createToken('auditee_test')->plainTextToken;

        // Should return empty list
        $response = $this->withToken($auditeeToken)->getJson('/api/faculty/my-reports');
        $response->assertOk()->assertJson(['data' => []]);

        // 2. Admin approves the audit
        $this->auditAssignment->update([
            'status' => AuditAssignmentStatus::Approved,
            'approved_at' => now(),
            'admin_remarks' => 'Good job on lecture delivery.',
        ]);

        // Now auditee can see the report
        $response = $this->withToken($auditeeToken)->getJson('/api/faculty/my-reports');
        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJson([
                'data' => [
                    [
                        'id' => $this->auditAssignment->id,
                        'total_score' => 85.0,
                        'admin_remarks' => 'Good job on lecture delivery.',
                    ],
                ],
            ]);
    }

    public function test_assigned_audits_surfaces_overdue_flags_and_due_in_days(): void
    {
        // Set due date to 2 days ago (overdue)
        $this->auditAssignment->update(['due_date' => now()->subDays(2)]);

        $token = $this->auditor->createToken('faculty_test')->plainTextToken;
        $response = $this->withToken($token)->getJson('/api/faculty/assigned-audits');

        $response->assertOk();
        $auditData = collect($response->json('data'))->firstWhere('id', $this->auditAssignment->id);

        $this->assertTrue($auditData['is_overdue']);
        $this->assertLessThan(0, $auditData['due_in_days']);
    }

    public function test_non_auditor_faculty_receives_403_on_save_draft_and_submit(): void
    {
        $nonAuditorToken = $this->auditee->createToken('non_auditor')->plainTextToken;

        // Try to save draft
        $this->withToken($nonAuditorToken)
            ->postJson("/api/faculty/audits/{$this->auditAssignment->id}/save-draft", [
                'answers' => [['question_id' => 5, 'value' => 4]],
            ])
            ->assertForbidden();

        // Try to submit
        $this->withToken($nonAuditorToken)
            ->postJson("/api/faculty/audits/{$this->auditAssignment->id}/submit", [
                'answers' => [['question_id' => 5, 'value' => 4]],
            ])
            ->assertForbidden();
    }
}

