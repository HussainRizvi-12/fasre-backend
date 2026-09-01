<?php

namespace Tests\Feature;

use App\Enums\AuditAssignmentStatus;
use App\Enums\UserRole;
use App\Models\AuditAssignment;
use App\Models\Section;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminAuditAssignmentApiTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;
    protected User $auditor;
    protected User $auditee;
    protected User $student;
    protected Section $section;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);

        $this->admin = User::where('role', UserRole::Admin)->first();
        $faculty = User::where('role', UserRole::Faculty)->get();
        $this->auditor = $faculty[0];
        $this->auditee = $faculty[1];
        $this->student = User::where('role', UserRole::Student)->first();
        $this->section = Section::first();
    }

    private function adminToken(): string
    {
        return $this->admin->createToken('admin_test')->plainTextToken;
    }

    public function test_admin_can_list_audit_assignments(): void
    {
        AuditAssignment::create([
            'auditor_id' => $this->auditor->id,
            'auditee_id' => $this->auditee->id,
            'section_id' => $this->section->id,
            'assigned_by' => $this->admin->id,
            'status' => AuditAssignmentStatus::Assigned,
        ]);

        $this->withToken($this->adminToken())
            ->getJson('/api/admin/audit-assignments')
            ->assertOk()
            ->assertJsonStructure([
                'data' => [
                    '*' => [
                        'id',
                        'auditor' => ['id', 'name', 'email'],
                        'auditee' => ['id', 'name', 'email'],
                        'section',
                        'course',
                        'status',
                    ],
                ],
            ]);
    }

    public function test_non_admin_cannot_access_admin_audit_api(): void
    {
        $token = $this->auditor->createToken('faculty_test')->plainTextToken;

        $this->withToken($token)
            ->getJson('/api/admin/audit-assignments')
            ->assertForbidden();
    }

    public function test_admin_can_create_audit_assignment(): void
    {
        $faculty = User::where('role', UserRole::Faculty)->get();
        $freshAuditor = $faculty[2]; // Usman Raza — no active audit in seed data

        $response = $this->withToken($this->adminToken())
            ->postJson('/api/admin/audit-assignments', [
                'auditor_id' => $freshAuditor->id,
                'auditee_id' => $this->auditee->id,
                'section_id' => $this->section->id,
                'due_date' => now()->addDays(7)->toDateString(),
            ]);

        $response->assertCreated()
            ->assertJsonPath('data.status', 'assigned')
            ->assertJsonPath('data.auditor.id', $freshAuditor->id);

        $this->assertDatabaseHas('audit_assignments', [
            'auditor_id' => $freshAuditor->id,
            'auditee_id' => $this->auditee->id,
            'status' => AuditAssignmentStatus::Assigned->value,
        ]);
    }

    public function test_cannot_assign_same_auditor_and_auditee(): void
    {
        $this->withToken($this->adminToken())
            ->postJson('/api/admin/audit-assignments', [
                'auditor_id' => $this->auditor->id,
                'auditee_id' => $this->auditor->id,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['auditee_id']);
    }

    public function test_cannot_assign_non_faculty_users(): void
    {
        $this->withToken($this->adminToken())
            ->postJson('/api/admin/audit-assignments', [
                'auditor_id' => $this->student->id,
                'auditee_id' => $this->auditee->id,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['auditor_id']);
    }

    public function test_admin_can_approve_submitted_audit(): void
    {
        $audit = AuditAssignment::create([
            'auditor_id' => $this->auditor->id,
            'auditee_id' => $this->auditee->id,
            'section_id' => $this->section->id,
            'assigned_by' => $this->admin->id,
            'status' => AuditAssignmentStatus::Submitted,
            'total_score' => 85.50,
            'submitted_at' => now(),
        ]);

        $this->withToken($this->adminToken())
            ->postJson("/api/admin/audit-assignments/{$audit->id}/approve", [
                'admin_remarks' => 'Excellent peer evaluation.',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'approved');

        $fresh = $audit->fresh();
        $this->assertEquals(AuditAssignmentStatus::Approved, $fresh->status);
        $this->assertEquals('Excellent peer evaluation.', $fresh->admin_remarks);
        $this->assertNotNull($fresh->approved_at);
    }

    public function test_admin_can_reject_submitted_audit_with_required_remarks(): void
    {
        $audit = AuditAssignment::create([
            'auditor_id' => $this->auditor->id,
            'auditee_id' => $this->auditee->id,
            'section_id' => $this->section->id,
            'assigned_by' => $this->admin->id,
            'status' => AuditAssignmentStatus::Submitted,
            'submitted_at' => now(),
        ]);

        // Reject without remarks fails
        $this->withToken($this->adminToken())
            ->postJson("/api/admin/audit-assignments/{$audit->id}/reject", [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['admin_remarks']);

        // Reject with remarks succeeds
        $this->withToken($this->adminToken())
            ->postJson("/api/admin/audit-assignments/{$audit->id}/reject", [
                'admin_remarks' => 'Insufficient evidence, please redo.',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'rejected');

        $fresh = $audit->fresh();
        $this->assertEquals(AuditAssignmentStatus::Rejected, $fresh->status);
        $this->assertNotNull($fresh->rejected_at);
    }

    public function test_cannot_approve_audit_that_is_not_submitted(): void
    {
        $audit = AuditAssignment::create([
            'auditor_id' => $this->auditor->id,
            'auditee_id' => $this->auditee->id,
            'section_id' => $this->section->id,
            'assigned_by' => $this->admin->id,
            'status' => AuditAssignmentStatus::Assigned,
        ]);

        $this->withToken($this->adminToken())
            ->postJson("/api/admin/audit-assignments/{$audit->id}/approve", [])
            ->assertUnprocessable();

        $this->assertEquals(AuditAssignmentStatus::Assigned, $audit->fresh()->status);
    }

    public function test_admin_can_view_single_audit_with_answers(): void
    {
        $audit = AuditAssignment::create([
            'auditor_id' => $this->auditor->id,
            'auditee_id' => $this->auditee->id,
            'section_id' => $this->section->id,
            'assigned_by' => $this->admin->id,
            'status' => AuditAssignmentStatus::Submitted,
            'answers_json' => ['5' => 4, '6' => true],
            'submitted_at' => now(),
        ]);

        $this->withToken($this->adminToken())
            ->getJson("/api/admin/audit-assignments/{$audit->id}")
            ->assertOk()
            ->assertJsonPath('data.answers_json.5', 4);
    }
}
