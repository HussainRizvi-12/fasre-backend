<?php

namespace Tests\Feature;

use App\Enums\AuditAssignmentStatus;
use App\Enums\FormType;
use App\Enums\UserRole;
use App\Models\AuditAssignment;
use App\Models\AuditProvenanceLog;
use App\Models\FormVersion;
use App\Models\Section;
use App\Models\User;
use App\Services\AuditScoringService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuditLifecycleAndScoringTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;
    protected User $auditor;
    protected User $auditee;
    protected User $otherFaculty;
    protected Section $section;
    protected AuditAssignment $auditAssignment;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);

        $this->admin = User::where('role', UserRole::Admin)->first();
        $faculty = User::where('role', UserRole::Faculty)->get();
        $this->auditor = $faculty[0];
        $this->auditee = $faculty[1];
        $this->otherFaculty = $faculty[2];

        $this->section = Section::first();

        $this->auditAssignment = AuditAssignment::create([
            'auditor_id' => $this->auditor->id,
            'auditee_id' => $this->auditee->id,
            'section_id' => $this->section->id,
            'assigned_by' => $this->admin->id,
            'status' => AuditAssignmentStatus::Approved,
            'total_score' => 88.0,
            'outcome_band' => 'Exemplary',
            'answers_json' => [
                '1' => 4,
                '2' => 5,
                '3' => 'yes',
            ],
            'comments_json' => [
                '1' => 'Great pacing and board organization.',
            ],
            'due_date' => now()->addDays(7),
            'approved_at' => now(),
        ]);
    }

    public function test_faculty_can_submit_formal_response_to_approved_report(): void
    {
        $auditeeToken = $this->auditee->createToken('auditee_token')->plainTextToken;

        $response = $this->withToken($auditeeToken)
            ->postJson("/api/faculty/my-reports/{$this->auditAssignment->id}/response", [
                'response_text' => 'I appreciate the constructive feedback regarding board organization. I will incorporate interactive group exercises.',
            ]);

        $response->assertOk()
            ->assertJsonPath('data.status', 'faculty_responded')
            ->assertJsonPath('data.faculty_response', 'I appreciate the constructive feedback regarding board organization. I will incorporate interactive group exercises.');

        $fresh = $this->auditAssignment->fresh();
        $this->assertEquals(AuditAssignmentStatus::FacultyResponded, $fresh->status);
        $this->assertNotNull($fresh->faculty_responded_at);
        $this->assertStringContainsString('interactive group exercises', $fresh->faculty_response);

        // Verify audit provenance log recorded
        $log = AuditProvenanceLog::where('auditable_id', $this->auditAssignment->id)
            ->where('action', 'faculty_response_submitted')
            ->first();

        $this->assertNotNull($log);
        $this->assertEquals($this->auditee->id, $log->actor_id);
        $this->assertEquals('approved', $log->before_state_json['status']);
        $this->assertEquals('faculty_responded', $log->after_state_json['status']);
    }

    public function test_unauthorized_faculty_cannot_respond_to_another_faculty_report(): void
    {
        $otherToken = $this->otherFaculty->createToken('other_token')->plainTextToken;

        $this->withToken($otherToken)
            ->postJson("/api/faculty/my-reports/{$this->auditAssignment->id}/response", [
                'response_text' => 'Attempting unauthorized response.',
            ])
            ->assertForbidden();
    }

    public function test_improvement_actions_full_lifecycle_and_closure(): void
    {
        $adminToken = $this->admin->createToken('admin_token')->plainTextToken;
        $auditeeToken = $this->auditee->createToken('auditee_token')->plainTextToken;

        // 1. Admin creates an improvement action item
        $createRes = $this->withToken($adminToken)
            ->postJson("/api/admin/audit-assignments/{$this->auditAssignment->id}/actions", [
                'finding' => 'Pacing in the second half of lecture felt rushed.',
                'agreed_action' => 'Submit updated lecture slides with timestamped checkpoint pauses.',
                'owner_id' => $this->auditee->id,
                'due_date' => now()->addDays(30)->toDateString(),
            ]);

        $createRes->assertStatus(201)
            ->assertJsonPath('data.finding', 'Pacing in the second half of lecture felt rushed.')
            ->assertJsonPath('data.status', 'open')
            ->assertJsonPath('data.audit_status', 'action_plan_active');

        $actionId = $createRes->json('data.id');
        $this->assertEquals(AuditAssignmentStatus::ActionPlanActive, $this->auditAssignment->fresh()->status);

        // 2. Faculty auditee views action items in their report
        auth()->forgetGuards();
        $facultyActionsRes = $this->withToken($auditeeToken)
            ->getJson("/api/faculty/my-reports/{$this->auditAssignment->id}/actions");

        $facultyActionsRes->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $actionId);

        // 3. Faculty auditee updates progress
        $updateRes = $this->withToken($auditeeToken)
            ->patchJson("/api/faculty/my-reports/{$this->auditAssignment->id}/actions/{$actionId}", [
                'status' => 'in_progress',
                'follow_up_note' => 'Revised lecture checkpoints slide deck has been drafted.',
            ]);

        $updateRes->assertOk()
            ->assertJsonPath('data.status', 'in_progress')
            ->assertJsonPath('data.follow_up_note', 'Revised lecture checkpoints slide deck has been drafted.');

        // Faculty cannot unilaterally close action item (closed is reserved for QA/admin)
        $this->withToken($auditeeToken)
            ->patchJson("/api/faculty/my-reports/{$this->auditAssignment->id}/actions/{$actionId}", [
                'status' => 'closed',
            ])
            ->assertUnprocessable();

        // 4. Admin reviews and closes action item
        auth()->forgetGuards();
        $closeRes = $this->withToken($adminToken)
            ->patchJson("/api/admin/audit-assignments/{$this->auditAssignment->id}/actions/{$actionId}", [
                'status' => 'closed',
                'follow_up_note' => 'Verified revised slide deck. Action satisfied.',
            ]);

        $closeRes->assertOk()
            ->assertJsonPath('data.status', 'closed')
            ->assertJsonPath('data.audit_status', 'closed');

        $this->assertEquals(AuditAssignmentStatus::Closed, $this->auditAssignment->fresh()->status);
        $this->assertDatabaseHas('audit_improvement_actions', [
            'id' => $actionId,
            'status' => 'closed',
            'closed_by' => $this->admin->id,
        ]);
    }

    public function test_centralized_scoring_math_and_outcome_bands(): void
    {
        // 1. Default bands test
        $answersExemplary = ['1' => 4.5, '2' => 4.8, '3' => 'yes'];
        $evalExemplary = AuditScoringService::evaluate($answersExemplary);
        $this->assertGreaterThanOrEqual(85.0, $evalExemplary['total_score']);
        $this->assertEquals('Exemplary', $evalExemplary['outcome_band']);
        $this->assertEquals('exceedsStandard', $evalExemplary['outcome']);
        $this->assertEquals('success', $evalExemplary['band_color']);

        $answersNeedsWork = ['1' => 2.0, '2' => 2.5, '3' => 'no'];
        $evalNeedsWork = AuditScoringService::evaluate($answersNeedsWork);
        $this->assertLessThan(60.0, $evalNeedsWork['total_score']);
        $this->assertEquals('Needs Improvement', $evalNeedsWork['outcome_band']);
        $this->assertEquals('needsImprovement', $evalNeedsWork['outcome']);
        $this->assertEquals('danger', $evalNeedsWork['band_color']);

        // 2. Custom FormVersion scoring bands test
        $customVersion = FormVersion::create([
            'form_type' => FormType::FacultyAudit,
            'version_code' => 'CUSTOM-RUBRIC-2026',
            'title' => 'Engineering Accreditation Rubric',
            'questions_json' => [],
            'scoring_rules_json' => [
                'formula' => 'percentage',
                'bands' => [
                    [
                        'key' => 'substandard',
                        'label' => 'Substandard',
                        'min' => 0.0,
                        'max' => 69.99,
                        'color' => 'danger',
                        'outcome' => 'needsImprovement',
                    ],
                    [
                        'key' => 'proficient',
                        'label' => 'Proficient',
                        'min' => 70.0,
                        'max' => 89.99,
                        'color' => 'info',
                        'outcome' => 'meetsStandard',
                    ],
                    [
                        'key' => 'distinguished',
                        'label' => 'Distinguished',
                        'min' => 90.0,
                        'max' => 100.0,
                        'color' => 'success',
                        'outcome' => 'exceedsStandard',
                    ],
                ],
            ],
            'is_published' => true,
            'created_by' => $this->admin->id,
        ]);

        // A score of 75% is "Proficient" under custom rubric (vs "Satisfactory" under default)
        $evalCustom = AuditScoringService::evaluate(['q1' => 3.75], $customVersion);
        $this->assertEquals(75.0, $evalCustom['total_score']);
        $this->assertEquals('Proficient', $evalCustom['outcome_band']);
        $this->assertEquals('meetsStandard', $evalCustom['outcome']);
        $this->assertEquals('info', $evalCustom['band_color']);
    }

    public function test_my_reports_includes_centralized_outcome_band_and_response_state(): void
    {
        $auditeeToken = $this->auditee->createToken('auditee_token')->plainTextToken;

        $response = $this->withToken($auditeeToken)
            ->getJson('/api/faculty/my-reports');

        $response->assertOk()
            ->assertJsonPath('data.0.id', $this->auditAssignment->id)
            ->assertJsonPath('data.0.total_score', '88.00')
            ->assertJsonPath('data.0.outcome_band', 'Exemplary')
            ->assertJsonPath('data.0.has_responded', false);
    }
}
