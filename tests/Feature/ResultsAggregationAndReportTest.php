<?php

namespace Tests\Feature;

use App\Enums\FormType;
use App\Enums\ReviewWindowStatus;
use App\Enums\UserRole;
use App\Models\Course;
use App\Models\Department;
use App\Models\Question;
use App\Models\ReviewResponse;
use App\Models\ReviewWindow;
use App\Models\Section;
use App\Models\StudentEnrollment;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class ResultsAggregationAndReportTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected User $student;

    protected ReviewWindow $publishedWindow;

    protected Section $section;

    protected array $questionIds;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);

        $this->admin = User::where('role', UserRole::Admin)->first();
        $this->student = User::where('role', UserRole::Student)->first();
        $this->section = Section::first();
        $this->questionIds = Question::where('form_type', FormType::StudentReview)->orderBy('sort_order')->pluck('id')->all();

        // Ensure student is enrolled in section
        StudentEnrollment::firstOrCreate([
            'section_id' => $this->section->id,
            'student_id' => $this->student->id,
        ]);

        $this->publishedWindow = ReviewWindow::create([
            'title' => 'Fall 2026 Published Window',
            'starts_at' => now()->subMonth(),
            'ends_at' => now()->subDay(),
            'status' => ReviewWindowStatus::Published,
        ]);
    }

    public function test_csv_export_respects_selected_section_and_retains_suppression(): void
    {
        $other = Section::create([
            'course_id' => $this->section->course_id,
            'name' => 'UNSELECTED_SECTION',
            'term' => $this->section->term,
            'is_active' => true,
        ]);
        $token = $this->admin->createToken('export-filter')->plainTextToken;
        $response = $this->withToken($token)->get('/api/admin/export/review-results?review_window_id='.$this->publishedWindow->id.'&section_id='.$this->section->id);
        $response->assertOk();
        $csv = $response->streamedContent();
        $this->assertStringNotContainsString($other->name, $csv);
        $this->assertStringContainsString('(suppressed', $csv);
        $lines = array_filter(explode("\n", trim($csv)));
        $this->assertCount(2, $lines);
        $this->assertSame($this->section->name, str_getcsv(array_values($lines)[1])[2]);
    }

    public function test_section_filter_cannot_export_another_departments_results(): void
    {
        $otherDept = Department::create(['name' => 'Export other department', 'code' => 'EXP', 'is_active' => true]);
        $otherCourse = Course::create(['department_id' => $otherDept->id, 'code' => 'EXP101', 'title' => 'Other course', 'is_active' => true]);
        $foreign = Section::create(['course_id' => $otherCourse->id, 'name' => 'PRIVATE_FOREIGN_SECTION', 'term' => $this->section->term, 'is_active' => true]);
        $scopedAdmin = User::factory()->create(['role' => UserRole::Admin, 'is_central_qa' => false, 'department_id' => $this->section->course->department_id]);
        $token = $scopedAdmin->createToken('scoped-export')->plainTextToken;
        $response = $this->withToken($token)->get('/api/admin/export/review-results?review_window_id='.$this->publishedWindow->id.'&section_id='.$foreign->id);
        $response->assertOk();
        $csv = $response->streamedContent();
        $this->assertStringNotContainsString($foreign->name, $csv);
        $this->assertStringNotContainsString($otherCourse->code, $csv);
        $this->assertCount(1, array_filter(explode("\n", trim($csv))));
    }

    public function test_selected_section_export_preserves_release_gate_and_validates_filter(): void
    {
        $token = $this->admin->createToken('export-gate')->plainTextToken;
        $this->withToken($token)->getJson('/api/admin/export/review-results?review_window_id='.$this->publishedWindow->id.'&section_id=invalid')->assertUnprocessable();
        ReviewWindow::where('status', ReviewWindowStatus::Active)->update(['status' => ReviewWindowStatus::Closed]);
        $this->publishedWindow->update(['status' => ReviewWindowStatus::Active]);
        $this->withToken($token)->getJson('/api/admin/export/review-results?review_window_id='.$this->publishedWindow->id.'&section_id='.$this->section->id)->assertUnprocessable();
    }

    public function test_suppression_triggers_at_four_responses_and_discloses_at_five(): void
    {
        $adminToken = $this->admin->createToken('admin_test')->plainTextToken;

        // 1. Insert 4 responses (below threshold of 5)
        for ($i = 0; $i < 4; $i++) {
            ReviewResponse::create([
                'review_window_id' => $this->publishedWindow->id,
                'section_id' => $this->section->id,
                'pseudonym_token' => (string) Str::uuid(),
                'answers_json' => [(string) $this->questionIds[0] => 4, (string) $this->questionIds[1] => true, (string) $this->questionIds[2] => 5, (string) $this->questionIds[3] => 'Good'],
                'submitted_at' => now(),
            ]);
        }

        $response = $this->withToken($adminToken)
            ->getJson("/api/admin/review-results?review_window_id={$this->publishedWindow->id}&section_id={$this->section->id}");

        $response->assertOk();
        $sectionResult = $response->json('data.0');
        $this->assertEquals(4, $sectionResult['response_count']);
        $this->assertTrue($sectionResult['is_suppressed']);
        $this->assertEmpty($sectionResult['questions']);

        // 2. Insert 5th response (reaches threshold of 5)
        ReviewResponse::create([
            'review_window_id' => $this->publishedWindow->id,
            'section_id' => $this->section->id,
            'pseudonym_token' => (string) Str::uuid(),
            'answers_json' => [(string) $this->questionIds[0] => 5, (string) $this->questionIds[1] => true, (string) $this->questionIds[2] => 5, (string) $this->questionIds[3] => 'Great'],
            'submitted_at' => now(),
        ]);

        $response = $this->withToken($adminToken)
            ->getJson("/api/admin/review-results?review_window_id={$this->publishedWindow->id}&section_id={$this->section->id}");

        $response->assertOk();
        $sectionResult = $response->json('data.0');
        $this->assertEquals(5, $sectionResult['response_count']);
        $this->assertFalse($sectionResult['is_suppressed']);
        $this->assertNotEmpty($sectionResult['questions']);
    }

    public function test_aggregation_calculations_are_mathematically_accurate(): void
    {
        $adminToken = $this->admin->createToken('admin_test')->plainTextToken;

        // Seeded questions:
        // Q1: Rating
        // Q2: YesNo
        // Q3: Rating
        // Q4: Textarea
        // Ratings for Q1: 3, 4, 5, 4, 4 -> sum=20 / 5 = 4.00
        // YesNos for Q2: true, true, false, true, true -> 4/5 = 80.00%
        $ratingsQ1 = [3, 4, 5, 4, 4];
        $yesNosQ2 = [true, true, false, true, true];

        for ($i = 0; $i < 5; $i++) {
            ReviewResponse::create([
                'review_window_id' => $this->publishedWindow->id,
                'section_id' => $this->section->id,
                'pseudonym_token' => (string) Str::uuid(),
                'answers_json' => [
                    (string) $this->questionIds[0] => $ratingsQ1[$i],
                    (string) $this->questionIds[1] => $yesNosQ2[$i],
                    (string) $this->questionIds[2] => 5,
                    (string) $this->questionIds[3] => "Feedback comment {$i}",
                ],
                'submitted_at' => now(),
            ]);
        }

        $response = $this->withToken($adminToken)
            ->getJson("/api/admin/review-results?review_window_id={$this->publishedWindow->id}&section_id={$this->section->id}");

        $response->assertOk();
        $questions = collect($response->json('data.0.questions'))->keyBy('question_id');

        // Verify Question #1 (Rating)
        $q1 = $questions->get($this->questionIds[0]);
        $this->assertEquals(4.0, $q1['average']);

        // Verify Question #2 (Yes/No)
        $q2 = $questions->get($this->questionIds[1]);
        $this->assertEquals(80.0, $q2['percentage_yes']);

        // Verify Question #4 (Text / Textarea: individual text is NEVER exposed)
        $q4 = $questions->get($this->questionIds[3]);
        $this->assertEquals(5, $q4['submission_count']);
        $this->assertArrayNotHasKey('answers', $q4);
    }

    public function test_students_can_only_view_published_review_results(): void
    {
        $studentToken = $this->student->createToken('student_test')->plainTextToken;

        // Seed 5 responses into the published window
        for ($i = 0; $i < 5; $i++) {
            ReviewResponse::create([
                'review_window_id' => $this->publishedWindow->id,
                'section_id' => $this->section->id,
                'pseudonym_token' => (string) Str::uuid(),
                'answers_json' => [(string) $this->questionIds[0] => 5, (string) $this->questionIds[1] => true, (string) $this->questionIds[2] => 5, (string) $this->questionIds[3] => 'Excellent'],
                'submitted_at' => now(),
            ]);
        }

        $response = $this->withToken($studentToken)
            ->getJson('/api/student/review-results/published');

        $response->assertOk()
            ->assertJsonStructure([
                'data' => [
                    '*' => [
                        'review_window' => ['id', 'title', 'published_at'],
                        'sections' => [
                            '*' => [
                                'section_id',
                                'section_name',
                                'course',
                                'response_count',
                                'is_suppressed',
                                'questions',
                            ],
                        ],
                    ],
                ],
            ]);

        $publishedWindowIds = collect($response->json('data'))->pluck('review_window.id');
        $this->assertTrue($publishedWindowIds->contains($this->publishedWindow->id));
    }

    public function test_cannot_modify_or_delete_question_during_active_review_window(): void
    {
        $adminToken = $this->admin->createToken('admin_test')->plainTextToken;

        // Close any existing active window to satisfy single-active constraint
        ReviewWindow::where('status', ReviewWindowStatus::Active)
            ->update(['status' => ReviewWindowStatus::Closed]);

        // Create an active review window
        $activeWindow = ReviewWindow::create([
            'title' => 'Active Review Window',
            'starts_at' => now()->subHour(),
            'ends_at' => now()->addDays(5),
            'status' => ReviewWindowStatus::Active,
        ]);

        $question = Question::where('form_type', FormType::StudentReview)->first();

        // Attempt update
        $updateRes = $this->withToken($adminToken)
            ->putJson("/api/admin/questions/{$question->id}", [
                'question_text' => 'Modified Question Text',
                'question_type' => 'rating',
                'form_type' => 'student_review',
            ]);
        $updateRes->assertStatus(422)
            ->assertJson(['message' => 'Cannot modify review questions while a review window is actively open.']);

        // Attempt delete
        $deleteRes = $this->withToken($adminToken)
            ->deleteJson("/api/admin/questions/{$question->id}");
        $deleteRes->assertStatus(422)
            ->assertJson(['message' => 'Cannot delete review questions while a review window is actively open.']);
    }

    public function test_cannot_delete_question_with_existing_review_responses(): void
    {
        $adminToken = $this->admin->createToken('admin_test')->plainTextToken;

        // Close any seeded active windows so this test focuses on existing response safeguard
        ReviewWindow::where('status', ReviewWindowStatus::Active)->update(['status' => ReviewWindowStatus::Closed]);

        // Record a response referencing question #1
        ReviewResponse::create([
            'review_window_id' => $this->publishedWindow->id,
            'section_id' => $this->section->id,
            'pseudonym_token' => (string) Str::uuid(),
            'answers_json' => [(string) $this->questionIds[0] => 5],
            'submitted_at' => now(),
        ]);

        $question1 = Question::findOrFail($this->questionIds[0]);

        $deleteRes = $this->withToken($adminToken)
            ->deleteJson("/api/admin/questions/{$question1->id}");
        $deleteRes->assertStatus(422)
            ->assertJson(['message' => 'Cannot delete question with existing recorded responses. Deactivate the question instead.']);
    }
}
