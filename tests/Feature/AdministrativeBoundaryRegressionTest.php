<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\AuditAssignment;
use App\Models\Course;
use App\Models\Department;
use App\Models\FacultyAssignment;
use App\Models\Section;
use App\Models\StudentEnrollment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdministrativeBoundaryRegressionTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Department $own;

    private Department $other;

    protected function setUp(): void
    {
        parent::setUp();
        config(['auth.mfa_enforced' => false]);
        $this->own = Department::create(['name' => 'Computing', 'code' => 'CS']);
        $this->other = Department::create(['name' => 'Engineering', 'code' => 'ENG']);
        $this->admin = $this->person('admin', $this->own);
        Sanctum::actingAs($this->admin);
    }

    private function person(string $role, ?Department $department): User
    {
        return User::factory()->create(['role' => $role, 'department_id' => $department?->id, 'is_active' => true]);
    }

    private function section(Department $department, string $code = 'CS101'): Section
    {
        $course = Course::create(['department_id' => $department->id, 'code' => $code, 'title' => 'Test course']);

        return Section::create(['course_id' => $course->id, 'name' => 'A', 'term' => 'Fall 2026']);
    }

    public function test_delegated_admin_cannot_import_a_central_admin(): void
    {
        $this->postJson('/api/admin/bulk-import', ['type' => 'users', 'csv' => "name,email,role\nEscalation,escalation@example.test,admin"])
            ->assertOk()->assertJsonPath('data.created', 0)->assertJsonPath('data.skipped', 1);
        $this->assertDatabaseMissing('users', ['email' => 'escalation@example.test']);
    }

    public function test_delegated_user_import_assigns_the_callers_department(): void
    {
        $this->postJson('/api/admin/bulk-import', ['type' => 'users', 'csv' => "name,email,role\nNew Student,new@example.test,student"])
            ->assertOk()->assertJsonPath('data.created', 1);
        $this->assertDatabaseHas('users', ['email' => 'new@example.test', 'department_id' => $this->own->id]);
    }

    public function test_delegated_import_cannot_create_courses_in_another_department(): void
    {
        $this->postJson('/api/admin/bulk-import', ['type' => 'courses', 'csv' => "department_code,code,title\nENG,ATTACK,Outside course"])
            ->assertOk()->assertJsonPath('data.created', 0);
        $this->assertDatabaseMissing('courses', ['code' => 'ATTACK']);
    }

    public function test_assignment_lists_and_deletes_are_department_scoped(): void
    {
        $outside = $this->section($this->other);
        $faculty = $this->person('faculty', $this->other);
        $student = $this->person('student', $this->other);
        $assignment = FacultyAssignment::create(['section_id' => $outside->id, 'faculty_id' => $faculty->id, 'is_primary' => true]);
        $enrollment = StudentEnrollment::create(['section_id' => $outside->id, 'student_id' => $student->id]);
        $this->getJson('/api/admin/faculty-assignments')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/admin/student-enrollments')->assertOk()->assertJsonCount(0, 'data');
        $this->deleteJson("/api/admin/faculty-assignments/{$assignment->id}")->assertForbidden();
        $this->deleteJson("/api/admin/student-enrollments/{$enrollment->id}")->assertForbidden();
        $this->assertModelExists($assignment);
        $this->assertModelExists($enrollment);
    }

    public function test_delegated_admin_cannot_assign_outside_sections_or_people(): void
    {
        $ownSection = $this->section($this->own);
        $outside = $this->section($this->other);
        foreach (['faculty-assignments' => 'faculty', 'student-enrollments' => 'student'] as $endpoint => $role) {
            $ownPerson = $this->person($role, $this->own);
            $otherPerson = $this->person($role, $this->other);
            $this->postJson("/api/admin/{$endpoint}", ['section_id' => $outside->id, "{$role}_id" => $ownPerson->id])->assertForbidden();
            $this->postJson("/api/admin/{$endpoint}", ['section_id' => $ownSection->id, "{$role}_id" => $otherPerson->id])->assertForbidden();
            $this->postJson("/api/admin/{$endpoint}", ['section_id' => $ownSection->id, "{$role}_id" => $ownPerson->id])->assertCreated();
        }
    }

    public function test_user_export_matches_user_list_scope(): void
    {
        $ownStudent = $this->person('student', $this->own);
        $outside = $this->person('student', $this->other);
        $csv = $this->get('/api/admin/export/users')->assertOk()->streamedContent();
        $this->assertStringContainsString($ownStudent->email, $csv);
        $this->assertStringNotContainsString($outside->email, $csv);
        $this->assertStringNotContainsString($this->admin->email, $csv);
    }

    public function test_global_logs_and_instrument_mutations_require_central_qa(): void
    {
        ActivityLog::create(['action' => 'private.activity', 'properties' => ['private' => 'campus-wide']]);
        $this->getJson('/api/admin/activity-logs')->assertForbidden();
        $this->getJson('/api/admin/export/activity-logs')->assertForbidden();
        $this->postJson('/api/admin/questions', ['form_type' => 'faculty_audit', 'question_type' => 'rating', 'question_text' => 'Changed globally'])->assertForbidden();
        $this->postJson('/api/admin/form-versions', ['form_type' => 'faculty_audit', 'version_code' => 'UNAUTHORIZED', 'title' => 'Changed globally'])->assertForbidden();
        Sanctum::actingAs($this->person('admin', null));
        $this->getJson('/api/admin/activity-logs')->assertOk();
    }

    public function test_import_accepts_utf8_bom_and_quoted_multiline_fields(): void
    {
        $this->postJson('/api/admin/bulk-import', ['type' => 'users', 'csv' => "\xEF\xBB\xBFname,email,role\n\"First\nLast\",multiline@example.test,student"])
            ->assertOk()->assertJsonPath('data.created', 1)->assertJsonPath('data.skipped', 0);
        $this->assertDatabaseHas('users', ['name' => "First\nLast", 'email' => 'multiline@example.test']);
    }

    public function test_import_rejects_duplicate_headers_and_incomplete_rows(): void
    {
        $this->postJson('/api/admin/bulk-import', ['type' => 'users', 'csv' => "name,email,email,role\nTest,one@example.test,two@example.test,student"])->assertUnprocessable();
        $this->postJson('/api/admin/bulk-import', ['type' => 'users', 'csv' => "name,email,role\nTest,one@example.test,student,unexpected"])->assertUnprocessable();
    }

    public function test_import_validates_email_and_does_not_assign_demo_password(): void
    {
        $this->postJson('/api/admin/bulk-import', ['type' => 'users', 'csv' => "name,email,role\nInvalid,not-an-email,student\nValid,valid@example.test,student"])
            ->assertOk()->assertJsonPath('data.created', 1)->assertJsonPath('data.skipped', 1);
        $this->assertFalse(Hash::check('Password@123', User::where('email', 'valid@example.test')->firstOrFail()->password));
    }

    public function test_ambiguous_course_codes_are_rejected_instead_of_silently_reassigned(): void
    {
        $this->section($this->own);
        $this->section($this->other);
        Sanctum::actingAs($this->person('admin', null));
        $this->postJson('/api/admin/bulk-import', ['type' => 'sections', 'csv' => "course_code,name,term\nCS101,B,Fall 2026"])
            ->assertOk()->assertJsonPath('data.created', 0)->assertJsonPath('data.skipped', 1);
    }

    public function test_pagination_rejects_negative_page_sizes_without_a_server_error(): void
    {
        Sanctum::actingAs($this->person('admin', null));
        $this->getJson('/api/admin/users?paginated=true&per_page=-1')->assertOk()->assertJsonPath('meta.per_page', 1);
        $this->getJson('/api/admin/activity-logs?per_page=-1')->assertOk()->assertJsonPath('meta.per_page', 1);
    }

    public function test_password_change_and_deactivation_revoke_existing_credentials(): void
    {
        $student = $this->person('student', $this->own);
        $student->createToken('old-device');
        $this->putJson("/api/admin/users/{$student->id}", ['password' => 'NewSecurePassword123!'])->assertOk();
        $this->assertSame(0, $student->tokens()->count());
        $student->createToken('another-device');
        $this->putJson("/api/admin/users/{$student->id}", ['is_active' => false])->assertOk();
        $this->assertSame(0, $student->tokens()->count());
    }

    public function test_deleting_people_cannot_destroy_academic_history(): void
    {
        $faculty = $this->person('faculty', $this->own);
        $auditor = $this->person('faculty', $this->own);
        $audit = AuditAssignment::create(['auditor_id' => $auditor->id, 'auditee_id' => $faculty->id, 'assigned_by' => $this->admin->id, 'status' => 'approved']);
        $this->deleteJson("/api/admin/users/{$faculty->id}")->assertUnprocessable();
        $this->assertModelExists($audit);
        $this->assertModelExists($faculty);
        $student = $this->person('student', $this->own);
        $section = $this->section($this->own);
        $enrollment = StudentEnrollment::create(['section_id' => $section->id, 'student_id' => $student->id]);
        $this->deleteJson("/api/admin/users/{$student->id}")->assertUnprocessable();
        $this->assertModelExists($enrollment);
    }

    public function test_admin_cannot_delete_or_demote_their_current_account(): void
    {
        $central = $this->person('admin', null);
        Sanctum::actingAs($central);
        $this->deleteJson("/api/admin/users/{$central->id}")->assertUnprocessable();
        $this->putJson("/api/admin/users/{$central->id}", ['is_active' => false])->assertUnprocessable();
        $this->putJson("/api/admin/users/{$central->id}", ['role' => 'student'])->assertUnprocessable();
        $this->putJson("/api/admin/users/{$central->id}", ['department_id' => $this->own->id])->assertUnprocessable();
    }

    public function test_dry_run_leaves_no_users_or_secrets_in_preview(): void
    {
        $res = $this->postJson('/api/admin/bulk-import', ['type' => 'users', 'dry_run' => true, 'csv' => "name,email,role,password\nPreview,preview@example.test,student,PreviewSecret123!"])
            ->assertOk()->assertJsonPath('data.valid_count', 1);
        $this->assertDatabaseMissing('users', ['email' => 'preview@example.test']);
        $this->assertStringNotContainsString('PreviewSecret123!', $res->getContent());
    }

    public function test_delegated_bulk_assignment_imports_cannot_cross_department_boundaries(): void
    {
        $outside = $this->section($this->other);
        $this->person('faculty', $this->own)->update(['email' => 'own.faculty@example.test']);
        $this->person('student', $this->own)->update(['email' => 'own.student@example.test']);
        foreach (['faculty-assignments' => 'faculty', 'student-enrollments' => 'student'] as $endpoint => $role) {
            $this->postJson('/api/admin/bulk-import', ['type' => $endpoint, 'csv' => "{$role}_email,course_code,section_name,term\nown.{$role}@example.test,CS101,A,Fall 2026"])
                ->assertOk()->assertJsonPath('data.created', 0)->assertJsonPath('data.skipped', 1);
        }
        $this->assertSame(0, FacultyAssignment::where('section_id', $outside->id)->count());
        $this->assertSame(0, StudentEnrollment::where('section_id', $outside->id)->count());
    }

    public function test_delegated_admin_cannot_clear_a_users_department(): void
    {
        $student = $this->person('student', $this->own);
        $this->putJson("/api/admin/users/{$student->id}", ['department_id' => null])->assertForbidden();
        $this->assertSame($this->own->id, $student->fresh()->department_id);
    }

    public function test_malformed_relationship_ids_return_validation_errors(): void
    {
        $this->postJson('/api/admin/student-enrollments', ['section_id' => [1], 'student_id' => [1]])->assertUnprocessable();
        $this->postJson('/api/admin/faculty-assignments', ['section_id' => [1], 'faculty_id' => [1]])->assertUnprocessable();
    }

    public function test_sectionless_audits_cannot_bypass_department_scope(): void
    {
        $auditor = $this->person('faculty', $this->other);
        $auditee = $this->person('faculty', $this->other);
        $this->postJson('/api/admin/audit-assignments', ['auditor_id' => $auditor->id, 'auditee_id' => $auditee->id])->assertForbidden();
    }
}
