<?php

namespace Tests\Feature;

use App\Models\AuditAssignment;
use App\Models\AuditEvidenceFile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class FacultyWorkflowRegressionTest extends TestCase
{
    use RefreshDatabase;

    private AuditAssignment $audit;

    private User $auditor;

    private User $auditee;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->auditor = User::factory()->create(['role' => 'faculty']);
        $this->auditee = User::factory()->create(['role' => 'faculty']);
        $admin = User::factory()->create(['role' => 'admin']);
        $this->audit = AuditAssignment::create(['auditor_id' => $this->auditor->id, 'auditee_id' => $this->auditee->id, 'assigned_by' => $admin->id, 'status' => 'assigned']);
        Sanctum::actingAs($this->auditor);
    }

    private function upload(string $identity, string $content = 'original')
    {
        return $this->postJson("/api/faculty/audits/{$this->audit->id}/evidence", [
            'file' => UploadedFile::fake()->createWithContent('proof.pdf', "%PDF-1.4\n{$content}\n%%EOF"),
            'client_attachment_id' => $identity,
        ]);
    }

    public function test_retried_attachment_has_one_identity_and_does_not_consume_another_quota_slot(): void
    {
        $identity = str_repeat('a', 64);
        $first = $this->upload($identity)->assertCreated()->json('data.id');
        $this->upload($identity)->assertOk()->assertJsonPath('data.id', $first);
        $this->assertDatabaseCount('audit_evidence_files', 1);
        $this->upload($identity, 'different')->assertUnprocessable();
    }

    public function test_photo_retry_identity_survives_metadata_stripping(): void
    {
        $originalImage = UploadedFile::fake()->image('photo.jpg', 8, 8);
        $bytes = file_get_contents($originalImage->getRealPath());
        $identity = str_repeat('c', 64);
        $upload = fn () => $this->postJson("/api/faculty/audits/{$this->audit->id}/evidence", [
            'file' => UploadedFile::fake()->createWithContent('photo.jpg', $bytes)->mimeType('image/jpeg'),
            'client_attachment_id' => $identity,
        ]);
        $first = $upload()->assertCreated()->json('data.id');
        $upload()->assertOk()->assertJsonPath('data.id', $first);
        $this->assertDatabaseCount('audit_evidence_files', 1);
    }

    public function test_identical_names_do_not_hide_different_files(): void
    {
        $this->upload(str_repeat('a', 64), 'first')->assertCreated();
        $this->upload(str_repeat('b', 64), 'other')->assertCreated();
        $this->assertDatabaseCount('audit_evidence_files', 2);
    }

    public function test_removal_is_idempotent_authorized_and_finalized_evidence_is_immutable(): void
    {
        $identity = str_repeat('a', 64);
        $this->upload($identity)->assertCreated();
        $path = AuditEvidenceFile::firstOrFail()->stored_path;
        Sanctum::actingAs($this->auditee);
        $this->deleteJson("/api/faculty/audits/{$this->audit->id}/evidence/{$identity}")->assertForbidden();
        Sanctum::actingAs($this->auditor);
        $this->audit->update(['status' => 'submitted']);
        $this->deleteJson("/api/faculty/audits/{$this->audit->id}/evidence/{$identity}")->assertUnprocessable();
        $this->audit->update(['status' => 'rejected']);
        $this->deleteJson("/api/faculty/audits/{$this->audit->id}/evidence/{$identity}")->assertNoContent();
        $this->deleteJson("/api/faculty/audits/{$this->audit->id}/evidence/{$identity}")->assertNoContent();
        Storage::disk('local')->assertMissing($path);
        $this->assertDatabaseCount('audit_evidence_files', 0);
    }

    public function test_returned_audit_keeps_qa_revision_context_during_autosave(): void
    {
        $this->audit->update(['status' => 'rejected', 'admin_remarks' => 'Clarify classroom pacing']);
        $this->postJson("/api/faculty/audits/{$this->audit->id}/save-draft", ['answers' => [], 'recommendations' => 'Allow time for questions'])
            ->assertOk()->assertJsonPath('data.status', 'rejected');
        $this->assertSame('Clarify classroom pacing', $this->audit->fresh()->admin_remarks);
    }

    public function test_approved_report_exposes_existing_recommendations_and_authorized_evidence(): void
    {
        $this->upload(str_repeat('a', 64))->assertCreated();
        $this->audit->update(['status' => 'approved', 'comments_json' => ['recommendations' => 'Use a worked example']]);
        Sanctum::actingAs($this->auditee);
        $this->getJson('/api/faculty/my-reports')->assertOk()
            ->assertJsonPath('data.0.recommendations', 'Use a worked example')
            ->assertJsonPath('data.0.evidence.0.original_name', 'proof.pdf')
            ->assertJsonPath('data.0.can_respond', true);
        $this->audit->update(['status' => 'closed']);
        $this->getJson('/api/faculty/my-reports')->assertOk()->assertJsonPath('data.0.can_respond', false);
    }

    public function test_formal_response_is_once_only_and_closed_audits_cannot_receive_it(): void
    {
        $this->audit->update(['status' => 'approved']);
        Sanctum::actingAs($this->auditee);
        $endpoint = "/api/faculty/my-reports/{$this->audit->id}/response";
        $this->postJson($endpoint, ['response_text' => 'My original response'])->assertOk();
        $this->postJson($endpoint, ['response_text' => 'Overwrite attempt'])->assertUnprocessable();
        $this->assertSame('My original response', $this->audit->fresh()->faculty_response);
        $this->audit->update(['status' => 'closed', 'faculty_response' => null]);
        $this->postJson($endpoint, ['response_text' => 'Closed response'])->assertUnprocessable();
    }
}
