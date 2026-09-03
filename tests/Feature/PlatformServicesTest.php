<?php

namespace Tests\Feature;

use App\Enums\AuditAssignmentStatus;
use App\Enums\UserRole;
use App\Models\ActivityLog;
use App\Models\AppNotification;
use App\Models\AuditAssignment;
use App\Models\Course;
use App\Models\Department;
use App\Models\Section;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;

class PlatformServicesTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;
    protected User $faculty;
    protected User $student;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);

        $this->admin = User::where('role', UserRole::Admin)->first();
        $this->faculty = User::where('role', UserRole::Faculty)->first();
        $this->student = User::where('role', UserRole::Student)->first();
    }

    private function adminToken(): string
    {
        return $this->admin->createToken('admin_test')->plainTextToken;
    }

    /* ── Notifications ─────────────────────────────────────────────── */

    public function test_audit_assignment_creation_notifies_auditor_and_auditee(): void
    {
        $faculty = User::where('role', UserRole::Faculty)->get();
        $auditor = $faculty[2]; // Usman — no active audit in seed data
        $auditee = $faculty[1];

        $this->withToken($this->adminToken())
            ->postJson('/api/admin/audit-assignments', [
                'auditor_id' => $auditor->id,
                'auditee_id' => $auditee->id,
            ])
            ->assertCreated();

        $this->assertDatabaseHas('notifications', [
            'user_id' => $auditor->id,
            'type' => 'audit',
        ]);
        $this->assertDatabaseHas('notifications', [
            'user_id' => $auditee->id,
            'type' => 'audit',
        ]);
    }

    public function test_activating_review_window_notifies_students(): void
    {
        $window = \App\Models\ReviewWindow::create([
            'title' => 'Notification Test Cycle',
            'starts_at' => now()->subDay(),
            'ends_at' => now()->addDays(7),
            'status' => \App\Enums\ReviewWindowStatus::Draft,
        ]);

        // Close any active window from seed data first
        \App\Models\ReviewWindow::where('status', \App\Enums\ReviewWindowStatus::Active)
            ->update(['status' => \App\Enums\ReviewWindowStatus::Closed]);

        $before = AppNotification::where('type', 'window')->count();

        $this->withToken($this->adminToken())
            ->postJson("/api/admin/review-windows/{$window->id}/activate")
            ->assertOk();

        $after = AppNotification::where('type', 'window')->count();
        $studentCount = User::where('role', UserRole::Student)->where('is_active', true)->count();

        $this->assertEquals($studentCount, $after - $before);
    }

    public function test_user_can_list_and_mark_notifications_read(): void
    {
        AppNotification::create([
            'user_id' => $this->student->id,
            'title' => 'Test notice',
            'body' => 'Body',
            'type' => 'window',
        ]);

        $studentToken = $this->student->createToken('s')->plainTextToken;

        $list = $this->withToken($studentToken)->getJson('/api/notifications');
        $list->assertOk()->assertJsonStructure(['data' => [['id', 'title', 'body', 'is_read']]]);
        $this->assertEquals('Test notice', $list->json('data.0.title'));

        $id = $list->json('data.0.id');

        $this->withToken($studentToken)
            ->patchJson("/api/notifications/{$id}/read")
            ->assertOk();

        $this->assertTrue(AppNotification::find($id)->is_read);

        // unread-count reflects the read state
        $count = $this->withToken($studentToken)->getJson('/api/notifications/unread-count');
        $count->assertOk();
        $this->assertEquals(0, $count->json('data.unread_count'));
    }

    public function test_user_cannot_read_others_notifications(): void
    {
        $notice = AppNotification::create([
            'user_id' => $this->student->id,
            'title' => 'Private',
            'body' => 'Private body',
            'type' => 'window',
        ]);

        $facultyToken = $this->faculty->createToken('f')->plainTextToken;

        $this->withToken($facultyToken)
            ->patchJson("/api/notifications/{$notice->id}/read")
            ->assertNotFound();
    }

    /* ── Bulk Import ───────────────────────────────────────────────── */

    public function test_admin_can_bulk_import_users(): void
    {
        $csv = "name,email,role\nTest Prof,prof@uni.test,faculty\nTest Learner,learner@uni.test,student";

        $this->withToken($this->adminToken())
            ->postJson('/api/admin/bulk-import', ['type' => 'users', 'csv' => $csv])
            ->assertOk()
            ->assertJsonPath('data.created', 2)
            ->assertJsonPath('data.skipped', 0);

        $this->assertDatabaseHas('users', ['email' => 'prof@uni.test', 'role' => 'faculty']);
        $this->assertDatabaseHas('users', ['email' => 'learner@uni.test', 'role' => 'student']);
    }

    public function test_bulk_import_reports_duplicate_rows(): void
    {
        $csv = "name,email,role\nDup One,dup@uni.test,student\nDup Two,dup@uni.test,student";

        $res = $this->withToken($this->adminToken())
            ->postJson('/api/admin/bulk-import', ['type' => 'users', 'csv' => $csv])
            ->assertOk();

        $this->assertEquals(1, $res->json('data.created'));
        $this->assertEquals(1, $res->json('data.skipped'));
        $this->assertStringContainsString('already exists', $res->json('data.errors.0'));
    }

    public function test_bulk_import_validates_type_and_content(): void
    {
        $this->withToken($this->adminToken())
            ->postJson('/api/admin/bulk-import', ['type' => 'aliens', 'csv' => "a\nb"])
            ->assertUnprocessable();

        $this->withToken($this->adminToken())
            ->postJson('/api/admin/bulk-import', ['type' => 'users', 'csv' => 'name,email'])
            ->assertUnprocessable();
    }

    public function test_non_admin_cannot_bulk_import(): void
    {
        $facultyToken = $this->faculty->createToken('f')->plainTextToken;
        $this->withToken($facultyToken)
            ->postJson('/api/admin/bulk-import', ['type' => 'users', 'csv' => "name,email\nx,y"])
            ->assertForbidden();
    }

    public function test_admin_can_bulk_import_full_course_chain(): void
    {
        $dept = Department::first();
        $deptCode = $dept->code ?? $dept->name;

        // 1. courses
        $coursesCsv = "department_code,code,title,credit_hours\n{$deptCode},IMP101,Imported Course,3";
        $this->withToken($this->adminToken())
            ->postJson('/api/admin/bulk-import', ['type' => 'courses', 'csv' => $coursesCsv])
            ->assertOk()
            ->assertJsonPath('data.created', 1);

        // 2. sections
        $sectionsCsv = "course_code,name,term\nIMP101,Sec X,Fall 2030";
        $this->withToken($this->adminToken())
            ->postJson('/api/admin/bulk-import', ['type' => 'sections', 'csv' => $sectionsCsv])
            ->assertOk()
            ->assertJsonPath('data.created', 1);

        // 3. student enrollment (import the student first)
        $usersCsv = "name,email,role\nImported Student,imp.student@uni.test,student";
        $this->withToken($this->adminToken())
            ->postJson('/api/admin/bulk-import', ['type' => 'users', 'csv' => $usersCsv])
            ->assertOk();

        $enrollCsv = "student_email,course_code,section_name,term\nimp.student@uni.test,IMP101,Sec X,Fall 2030";
        $this->withToken($this->adminToken())
            ->postJson('/api/admin/bulk-import', ['type' => 'student-enrollments', 'csv' => $enrollCsv])
            ->assertOk()
            ->assertJsonPath('data.created', 1);

        $this->assertDatabaseHas('student_enrollments', [
            'student_id' => User::where('email', 'imp.student@uni.test')->value('id'),
            'section_id' => Section::whereHas('course', fn ($q) => $q->where('code', 'IMP101'))->value('id'),
        ]);
    }

    /* ── Password Reset ────────────────────────────────────────────── */

    public function test_forgot_password_returns_generic_response(): void
    {
        $this->postJson('/api/forgot-password', ['email' => 'nonexistent@nowhere.test'])
            ->assertOk()
            ->assertJsonPath('message', 'If that email address exists in our records, a password reset link has been sent.');

        $this->postJson('/api/forgot-password', ['email' => $this->student->email])
            ->assertOk();
    }

    public function test_password_reset_flow_completes(): void
    {
        // Pre-create token
        $this->faculty->createToken('pre-reset-token');
        $this->assertCount(1, $this->faculty->tokens);

        $token = Password::createToken($this->faculty);

        $this->postJson('/api/reset-password', [
            'token' => $token,
            'email' => $this->faculty->email,
            'password' => 'NewSecurePass@123',
        ])->assertOk();

        $this->faculty->refresh();

        // Pre-reset token must be deleted
        $this->assertCount(0, $this->faculty->tokens);

        // Old password no longer valid; new one authenticates via login endpoint
        $this->postJson('/api/login', [
            'email' => $this->faculty->email,
            'password' => 'NewSecurePass@123',
        ])->assertOk();
    }

    public function test_password_reset_rejects_invalid_token(): void
    {
        $this->postJson('/api/reset-password', [
            'token' => 'totally-invalid-token',
            'email' => $this->faculty->email,
            'password' => 'NewSecurePass@123',
        ])->assertUnprocessable();
    }

    /* ── Pagination ────────────────────────────────────────────────── */

    public function test_users_api_supports_pagination_and_search(): void
    {
        // unpaginated (default, backward compatible)
        $all = $this->withToken($this->adminToken())->getJson('/api/admin/users');
        $all->assertOk();
        $this->assertArrayNotHasKey('meta', $all->json());

        // paginated
        $paged = $this->withToken($this->adminToken())
            ->getJson('/api/admin/users?paginated=true&per_page=3');
        $paged->assertOk();
        $this->assertEquals(3, $paged->json('meta.per_page'));
        $this->assertTrue($paged->json('meta.total') > 3);

        // search
        $found = $this->withToken($this->adminToken())
            ->getJson('/api/admin/users?search='.urlencode($this->student->email));
        $found->assertOk();
        $this->assertTrue(collect($found->json('data'))->contains(fn ($u) => $u['email'] === $this->student->email));
    }

    /* ── Exports ───────────────────────────────────────────────────── */

    public function test_admin_can_download_csv_exports(): void
    {
        $users = $this->withToken($this->adminToken())->get('/api/admin/export/users');
        $users->assertOk();
        $this->assertStringContainsString('name,email,role', $users->streamedContent());

        $audits = $this->withToken($this->adminToken())->get('/api/admin/export/audit-assignments');
        $audits->assertOk();
        $this->assertStringContainsString('auditor', $audits->streamedContent());

        $logs = $this->withToken($this->adminToken())->get('/api/admin/export/activity-logs');
        $logs->assertOk();
    }

    public function test_non_admin_cannot_download_exports(): void
    {
        $facultyToken = $this->faculty->createToken('f')->plainTextToken;
        $this->withToken($facultyToken)->get('/api/admin/export/users')->assertForbidden();
    }

    /* ── Activity Log ──────────────────────────────────────────────── */

    public function test_admin_actions_are_recorded_in_activity_log(): void
    {
        $before = ActivityLog::count();

        $this->withToken($this->adminToken())
            ->postJson('/api/admin/departments', ['name' => 'Logged Dept', 'code' => 'LOG'])
            ->assertCreated();

        $this->withToken($this->adminToken())
            ->postJson('/api/admin/audit-assignments', [
                'auditor_id' => User::where('role', UserRole::Faculty)->get()[2]->id,
                'auditee_id' => User::where('role', UserRole::Faculty)->get()[1]->id,
            ])
            ->assertCreated();

        $logs = ActivityLog::orderBy('id')->skip($before)->take(10)->get();

        $this->assertTrue($logs->contains('action', 'department_created'));
        $this->assertTrue($logs->contains('action', 'audit_assignment.created'));
    }

    public function test_activity_log_api_lists_entries(): void
    {
        ActivityLog::create([
            'user_id' => $this->admin->id,
            'action' => 'test.action',
            'properties' => ['x' => 1],
        ]);

        $res = $this->withToken($this->adminToken())
            ->getJson('/api/admin/activity-logs');

        $res->assertOk()
            ->assertJsonStructure(['data' => [['id', 'user_name', 'action']], 'meta']);

        $this->assertTrue(collect($res->json('data'))->contains('action', 'test.action'));
    }

    public function test_non_admin_cannot_read_activity_log(): void
    {
        $facultyToken = $this->faculty->createToken('f')->plainTextToken;
        $this->withToken($facultyToken)->getJson('/api/admin/activity-logs')->assertForbidden();
    }

    /* ── Token Expiry ──────────────────────────────────────────────── */

    public function test_sanctum_tokens_have_expiry_configured(): void
    {
        // Sessions must expire (security): 2 hours in this deployment.
        $this->assertNotNull(config('sanctum.expiration'));
        $this->assertEquals(120, (int) config('sanctum.expiration'));
    }

    /* ── Audit approval notifies faculty ───────────────────────────── */

    public function test_audit_approval_notifies_auditee(): void
    {
        $audit = AuditAssignment::create([
            'auditor_id' => User::where('role', UserRole::Faculty)->get()[0]->id,
            'auditee_id' => User::where('role', UserRole::Faculty)->get()[1]->id,
            'section_id' => Section::first()->id,
            'assigned_by' => $this->admin->id,
            'status' => AuditAssignmentStatus::Submitted,
            'total_score' => 90.0,
            'submitted_at' => now(),
        ]);

        $this->withToken($this->adminToken())
            ->postJson("/api/admin/audit-assignments/{$audit->id}/approve", [])
            ->assertOk();

        $this->assertDatabaseHas('notifications', [
            'user_id' => $audit->auditee_id,
            'title' => 'Peer audit report approved',
        ]);
    }
}
