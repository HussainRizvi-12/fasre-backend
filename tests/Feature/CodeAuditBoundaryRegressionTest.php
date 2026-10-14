<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\Department;
use App\Models\FormVersion;
use App\Models\Question;
use App\Models\Section;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class CodeAuditBoundaryRegressionTest extends TestCase
{
    use RefreshDatabase;

    private function adminToken(): string
    {
        return User::factory()->create(['role' => 'admin', 'is_central_qa' => true, 'department_id' => null])->createToken('audit-test')->plainTextToken;
    }

    public static function invalidBands(): array
    {
        return [
            'reversed' => [[['min' => 90, 'max' => 10, 'label' => 'Reversed']]],
            'label-shape' => [[['min' => 0, 'max' => 100, 'label' => []]]],
            'overlap' => [[['min' => 0, 'max' => 70, 'label' => 'First'], ['min' => 60, 'max' => 100, 'label' => 'Second']]],
            'outcome' => [[['min' => 0, 'max' => 100, 'label' => 'Band', 'outcome' => 'madeUp']]],
            'range' => [[['min' => -1, 'max' => 101, 'label' => 'Outside']]],
            'container' => ['not-an-array'],
            'gap' => [[['min' => 0, 'max' => 59, 'label' => 'First'], ['min' => 60, 'max' => 100, 'label' => 'Second']]],
            'missing-zero' => [[['min' => 10, 'max' => 100, 'label' => 'Incomplete']]],
            'missing-hundred' => [[['min' => 0, 'max' => 90, 'label' => 'Incomplete']]],
        ];
    }

    #[DataProvider('invalidBands')]
    public function test_invalid_frozen_scoring_rules_are_rejected_without_publishing(mixed $bands): void
    {
        Question::create(['form_type' => 'faculty_audit', 'question_text' => 'Rating', 'question_type' => 'rating', 'is_active' => true, 'is_required' => true, 'sort_order' => 1]);
        $this->withToken($this->adminToken())->postJson('/api/admin/form-versions', [
            'form_type' => 'faculty_audit', 'version_code' => 'invalid', 'title' => 'Invalid rubric', 'scoring_rules_json' => ['bands' => $bands],
        ])->assertUnprocessable();
        $this->assertDatabaseCount('form_versions', 0);
    }

    public function test_valid_custom_scoring_rules_are_retained(): void
    {
        Question::create(['form_type' => 'faculty_audit', 'question_text' => 'Rating', 'question_type' => 'rating', 'is_active' => true, 'is_required' => true, 'sort_order' => 1]);
        $bands = [['min' => 0, 'max' => 69.99, 'label' => 'Developing'], ['min' => 70, 'max' => 100, 'label' => 'Proficient', 'outcome' => 'meetsStandard']];
        $this->withToken($this->adminToken())->postJson('/api/admin/form-versions', [
            'form_type' => 'faculty_audit', 'version_code' => 'valid', 'title' => 'Valid rubric', 'scoring_rules_json' => ['bands' => $bands],
        ])->assertCreated();
        $saved = FormVersion::firstOrFail()->scoring_rules_json['bands'];
        $this->assertEquals(69.99, $saved[0]['max']);
        $this->assertSame('meetsStandard', $saved[1]['outcome']);
    }

    public function test_archived_course_cannot_receive_a_new_or_reparented_section(): void
    {
        $department = Department::create(['name' => 'Academic', 'code' => 'ACA']);
        $course = Course::create(['department_id' => $department->id, 'code' => 'ACA101', 'title' => 'Current']);
        $archived = Course::create(['department_id' => $department->id, 'code' => 'OLD101', 'title' => 'Archived']);
        $archived->delete();
        $section = Section::create(['course_id' => $course->id, 'name' => 'Existing', 'term' => 'Fall 2026']);
        $token = $this->adminToken();
        $this->withToken($token)->postJson('/api/admin/sections', ['course_id' => $archived->id, 'name' => 'Detached', 'term' => 'Fall 2026'])->assertUnprocessable();
        $this->withToken($token)->patchJson('/api/admin/sections/'.$section->id, ['course_id' => $archived->id])->assertUnprocessable();
        $this->assertSame($course->id, $section->fresh()->course_id);
        $this->assertDatabaseCount('sections', 1);
    }

    public function test_section_csv_reports_invalid_rows_and_retains_valid_rows(): void
    {
        $department = Department::create(['name' => 'Import', 'code' => 'IMP']);
        $course = Course::create(['department_id' => $department->id, 'code' => 'IMP101', 'title' => 'Import']);
        $csv = "course_code,name,term\nIMP101,Good,Fall 2026\nIMP101,".str_repeat('X', 300).",Fall 2026\nIMP101,Wrong term,".str_repeat('T', 101);
        $this->withToken($this->adminToken())->postJson('/api/admin/bulk-import', ['type' => 'sections', 'csv' => $csv])
            ->assertOk()->assertJsonPath('data.created', 1)->assertJsonPath('data.skipped', 2)->assertJsonCount(2, 'data.errors');
        $this->assertDatabaseHas('sections', ['course_id' => $course->id, 'name' => 'Good']);
        $this->assertDatabaseCount('sections', 1);
    }

    public function test_qualitative_audit_remains_unscored_through_submission_approval_and_report(): void
    {
        $auditor = User::factory()->create(['role' => 'faculty']);
        $auditee = User::factory()->create(['role' => 'faculty']);
        $form = FormVersion::create(['form_type' => 'faculty_audit', 'version_code' => 'qualitative', 'title' => 'Notes', 'is_published' => true,
            'questions_json' => [['id' => 1, 'question_text' => 'Notes', 'question_type' => 'text', 'is_required' => true, 'sort_order' => 1]]]);
        $admin = $this->adminToken();
        $assignment = $this->withToken($admin)->postJson('/api/admin/audit-assignments', [
            'auditor_id' => $auditor->id, 'auditee_id' => $auditee->id, 'form_version_id' => $form->id,
        ])->assertCreated()->json('data.id');
        Auth::forgetGuards();
        $this->withToken($auditor->createToken('auditor')->plainTextToken)->postJson('/api/faculty/audits/'.$assignment.'/submit', [
            'answers' => [['question_id' => 1, 'value' => 'Qualitative observations']],
        ])->assertOk()->assertJsonPath('data.total_score', null);
        Auth::forgetGuards();
        $this->withToken($admin)->postJson('/api/admin/audit-assignments/'.$assignment.'/approve')->assertOk();
        Auth::forgetGuards();
        $this->withToken($auditee->createToken('auditee')->plainTextToken)->getJson('/api/faculty/my-reports')
            ->assertOk()->assertJsonPath('data.0.total_score', null)->assertJsonPath('data.0.outcome_band', null)
            ->assertJsonPath('data.0.outcome', null)->assertJsonPath('data.0.band_color', null)
            ->assertJsonPath('data.0.breakdown.0.value', 'Qualitative observations');
    }
}
