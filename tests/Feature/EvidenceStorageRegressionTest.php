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

class EvidenceStorageRegressionTest extends TestCase
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

    private function pdf(): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('evidence.pdf', "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\n%%EOF");
    }

    public function test_pdf_upload_and_download_obey_role_and_approval_gates(): void
    {
        $response = $this->postJson("/api/faculty/audits/{$this->audit->id}/evidence", ['file' => $this->pdf()])->assertCreated();
        $fileId = $response->json('data.id');
        $this->get("/api/faculty/evidence/{$fileId}/download")->assertOk();
        Sanctum::actingAs($this->auditee);
        $this->getJson("/api/faculty/evidence/{$fileId}/download")->assertForbidden();
        $this->audit->update(['status' => 'approved']);
        $this->get("/api/faculty/evidence/{$fileId}/download")->assertOk();
        Sanctum::actingAs(User::factory()->create(['role' => 'faculty']));
        $this->getJson("/api/faculty/evidence/{$fileId}/download")->assertForbidden();
    }

    public function test_failed_storage_write_does_not_create_a_successful_attachment(): void
    {
        Storage::disk('local');
        Storage::shouldReceive('disk')->with('local')->andReturn(new class
        {
            public function putFileAs(...$args)
            {
                return false;
            }
        });
        $this->postJson("/api/faculty/audits/{$this->audit->id}/evidence", ['file' => $this->pdf()])->assertStatus(503);
        $this->assertDatabaseCount('audit_evidence_files', 0);
    }

    public function test_an_unversioned_audit_cannot_attach_evidence_to_an_unknown_question(): void
    {
        $this->postJson("/api/faculty/audits/{$this->audit->id}/evidence", ['file' => $this->pdf(), 'question_id' => 999999])->assertUnprocessable();
    }

    public function test_quota_and_finalization_prevent_new_files(): void
    {
        for ($i = 0; $i < 10; $i++) {
            AuditEvidenceFile::create(['audit_assignment_id' => $this->audit->id, 'original_name' => 'quota.pdf', 'stored_path' => "quota/{$i}.pdf", 'mime_type' => 'application/pdf', 'size_bytes' => 1]);
        }
        $this->postJson("/api/faculty/audits/{$this->audit->id}/evidence", ['file' => $this->pdf()])->assertUnprocessable();
        $this->audit->update(['status' => 'submitted']);
        $this->postJson("/api/faculty/audits/{$this->audit->id}/evidence", ['file' => $this->pdf()])->assertUnprocessable();
        $this->assertDatabaseCount('audit_evidence_files', 10);
    }

    public function test_photo_sanitization_uses_detected_type_and_records_actual_stored_size(): void
    {
        $image = UploadedFile::fake()->image('photo.jpg', 8, 8);
        $bytes = file_get_contents($image->getRealPath());
        $marker = 'PRIVATE_PHOTO_METADATA';
        $jpeg = substr($bytes, 0, 2)."\xFF\xFE".pack('n', strlen($marker) + 2).$marker.substr($bytes, 2);
        $this->postJson("/api/faculty/audits/{$this->audit->id}/evidence", ['file' => UploadedFile::fake()->createWithContent('photo.bin', $jpeg)->mimeType('image/jpeg')])->assertCreated();
        $record = AuditEvidenceFile::firstOrFail();
        $stored = Storage::disk('local')->get($record->stored_path);
        $this->assertStringNotContainsString($marker, $stored);
        $this->assertSame(strlen($stored), $record->size_bytes);
        $this->assertStringEndsWith('.jpg', $record->stored_path);
    }

    public function test_database_failure_removes_the_file_written_before_rollback(): void
    {
        AuditEvidenceFile::creating(fn () => throw new \RuntimeException('Simulated database failure'));
        try {
            $this->postJson("/api/faculty/audits/{$this->audit->id}/evidence", ['file' => $this->pdf()])->assertStatus(500);
            $this->assertDatabaseCount('audit_evidence_files', 0);
            $this->assertSame([], Storage::disk('local')->allFiles());
        } finally {
            AuditEvidenceFile::flushEventListeners();
        }
    }

    public function test_undecodable_photo_is_rejected_instead_of_preserving_metadata(): void
    {
        $file = UploadedFile::fake()->createWithContent('photo.jpg', 'invalid-image-data')->mimeType('image/jpeg');
        $this->postJson("/api/faculty/audits/{$this->audit->id}/evidence", ['file' => $file])->assertUnprocessable();
        $this->assertDatabaseCount('audit_evidence_files', 0);
        $this->assertSame([], Storage::disk('local')->allFiles());
    }
}
