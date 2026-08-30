<?php

namespace Tests\Feature;

use App\Enums\FormType;
use App\Enums\QuestionType;
use App\Enums\ReviewWindowStatus;
use App\Enums\UserRole;
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

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);

        $this->admin = User::where('role', UserRole::Admin)->first();
        $this->student = User::where('role', UserRole::Student)->first();
        $this->section = Section::first();

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

    public function test_suppression_triggers_at_four_responses_and_discloses_at_five(): void
    {
        $adminToken = $this->admin->createToken('admin_test')->plainTextToken;

        // 1. Insert 4 responses (below threshold of 5)
        for ($i = 0; $i < 4; $i++) {
            ReviewResponse::create([
                'review_window_id' => $this->publishedWindow->id,
                'section_id' => $this->section->id,
                'pseudonym_token' => (string) Str::uuid(),
                'answers_json' => ['1' => 4, '2' => true, '3' => 5, '4' => 'Good'],
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
            'answers_json' => ['1' => 5, '2' => true, '3' => 5, '4' => 'Great'],
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
                    '1' => $ratingsQ1[$i],
                    '2' => $yesNosQ2[$i],
                    '3' => 5,
                    '4' => "Feedback comment {$i}",
                ],
                'submitted_at' => now(),
            ]);
        }

        $response = $this->withToken($adminToken)
            ->getJson("/api/admin/review-results?review_window_id={$this->publishedWindow->id}&section_id={$this->section->id}");

        $response->assertOk();
        $questions = collect($response->json('data.0.questions'))->keyBy('question_id');

        // Verify Question #1 (Rating)
        $q1 = $questions->get(1);
        $this->assertEquals(4.0, $q1['average']);

        // Verify Question #2 (Yes/No)
        $q2 = $questions->get(2);
        $this->assertEquals(80.0, $q2['percentage_yes']);

        // Verify Question #4 (Text / Textarea: individual text is NEVER exposed)
        $q4 = $questions->get(4);
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
                'answers_json' => ['1' => 5, '2' => true, '3' => 5, '4' => 'Excellent'],
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
            'answers_json' => ['1' => 5],
            'submitted_at' => now(),
        ]);

        $question1 = Question::find(1);

        $deleteRes = $this->withToken($adminToken)
            ->deleteJson("/api/admin/questions/{$question1->id}");
        $deleteRes->assertStatus(422)
            ->assertJson(['message' => 'Cannot delete question with existing recorded responses. Deactivate the question instead.']);
    }
}

