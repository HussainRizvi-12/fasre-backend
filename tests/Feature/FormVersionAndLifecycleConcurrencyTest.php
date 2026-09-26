<?php

namespace Tests\Feature;

use App\Enums\AuditAssignmentStatus;
use App\Enums\FormType;
use App\Enums\ReviewWindowStatus;
use App\Enums\UserRole;
use App\Models\AuditAssignment;
use App\Models\Department;
use App\Models\FormVersion;
use App\Models\Question;
use App\Models\ReviewWindow;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class FormVersionAndLifecycleConcurrencyTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;
    protected Department $department;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);

        $this->admin = User::where('role', UserRole::Admin)->first();
        $this->department = Department::first();
    }

    public function test_can_list_and_publish_form_versions(): void
    {
        $token = $this->admin->createToken('admin_test')->plainTextToken;

        // 1. Publish new form version
        $publishRes = $this->withToken($token)->postJson('/api/admin/form-versions', [
            'form_type' => 'student_review',
            'version_code' => 'STUDENT-V99',
            'title' => 'Student Review 2026/27 Pilot Instrument',
            'description' => 'Frozen rubric with normalized scoring weights',
        ]);

        $publishRes->assertCreated();
        $this->assertEquals('STUDENT-V99', $publishRes->json('data.version_code'));
        $this->assertGreaterThan(0, $publishRes->json('data.questions_count'));

        // 2. List form versions and verify published version is present
        $listRes = $this->withToken($token)->getJson('/api/admin/form-versions?form_type=student_review');
        $listRes->assertOk();
        $codes = collect($listRes->json('data'))->pluck('version_code');
        $this->assertTrue($codes->contains('STUDENT-V99'));

        // 3. Verify in database
        $this->assertDatabaseHas('form_versions', [
            'version_code' => 'STUDENT-V99',
            'is_published' => true,
        ]);
    }

    public function test_database_invariant_prevents_multiple_concurrent_active_windows(): void
    {
        ReviewWindow::where('status', ReviewWindowStatus::Active)
            ->update(['status' => ReviewWindowStatus::Closed]);

        // First active window succeeds
        ReviewWindow::create([
            'department_id' => $this->department->id,
            'title' => 'Active Window 1',
            'term' => 'Fall 2026',
            'starts_at' => now(),
            'ends_at' => now()->addDays(7),
            'status' => ReviewWindowStatus::Active,
        ]);

        // Second active window must violate unique index at database level
        $this->expectException(QueryException::class);

        ReviewWindow::create([
            'department_id' => $this->department->id,
            'title' => 'Active Window 2 (Concurrent)',
            'term' => 'Fall 2026',
            'starts_at' => now(),
            'ends_at' => now()->addDays(7),
            'status' => ReviewWindowStatus::Active,
        ]);
    }

    public function test_scheduled_artisan_command_closes_expired_active_windows(): void
    {
        ReviewWindow::where('status', ReviewWindowStatus::Active)
            ->update(['status' => ReviewWindowStatus::Closed]);

        $expiredWindow = ReviewWindow::create([
            'department_id' => $this->department->id,
            'title' => 'Expired Active Window',
            'term' => 'Fall 2026',
            'starts_at' => now()->subDays(14),
            'ends_at' => now()->subDay(), // Expired yesterday
            'status' => ReviewWindowStatus::Active,
        ]);

        $this->assertEquals(ReviewWindowStatus::Active, $expiredWindow->status);

        // Run auto-close command
        Artisan::call('fasre:close-expired-windows');

        $expiredWindow->refresh();
        $this->assertEquals(ReviewWindowStatus::Closed, $expiredWindow->status);
    }

    public function test_admin_can_close_approved_audit_with_no_action_closure(): void
    {
        $token = $this->admin->createToken('admin_test')->plainTextToken;

        $audit = AuditAssignment::first();
        $audit->update([
            'status' => AuditAssignmentStatus::Approved,
            'admin_remarks' => 'Meets standard. Observation complete.',
        ]);

        $response = $this->withToken($token)->postJson("/api/admin/audit-assignments/{$audit->id}/close", [
            'closure_remarks' => 'Peer review requirements fulfilled. No action plan necessary.',
        ]);

        $response->assertOk();
        $audit->refresh();
        $this->assertEquals(AuditAssignmentStatus::Closed, $audit->status);
        $this->assertStringContainsString('No action plan necessary', $audit->admin_remarks);
    }
}
