<?php

namespace Tests\Feature;

use App\Enums\FormType;
use App\Enums\QuestionType;
use App\Enums\ReviewWindowStatus;
use App\Enums\UserRole;
use App\Models\Question;
use App\Models\ReviewParticipation;
use App\Models\ReviewResponse;
use App\Models\ReviewWindow;
use App\Models\Section;
use App\Models\StudentEnrollment;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class StudentReviewApiTest extends TestCase
{
    use RefreshDatabase;

    protected User $student;
    protected User $faculty;
    protected User $admin;
    protected ReviewWindow $activeWindow;
    protected Section $enrolledSection;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);

        $this->student = User::where('role', UserRole::Student)->first();
        $this->faculty = User::where('role', UserRole::Faculty)->first();
        $this->admin = User::where('role', UserRole::Admin)->first();

        $this->activeWindow = ReviewWindow::where('status', ReviewWindowStatus::Active)->first();
        $this->enrolledSection = StudentEnrollment::where('student_id', $this->student->id)->first()->section;
    }

    // ── Role Authorization Tests ────────────────────────────────────

    public function test_unauthenticated_user_cannot_access_student_apis(): void
    {
        $this->getJson('/api/student/enrolled-sections')
            ->assertUnauthorized();
    }

    public function test_admin_and_faculty_cannot_access_student_apis(): void
    {
        $adminToken = $this->admin->createToken('admin_test')->plainTextToken;
        $facultyToken = $this->faculty->createToken('faculty_test')->plainTextToken;

        $this->withToken($adminToken)
            ->getJson('/api/student/enrolled-sections')
            ->assertForbidden()
            ->assertJson(['message' => 'Forbidden. Student access required.']);

        $this->withToken($facultyToken)
            ->getJson('/api/student/enrolled-sections')
            ->assertForbidden()
            ->assertJson(['message' => 'Forbidden. Student access required.']);
    }

    // ── 4.1 GET /api/student/enrolled-sections ─────────────────────

    public function test_student_can_view_enrolled_sections_with_review_status(): void
    {
        $token = $this->student->createToken('student_test')->plainTextToken;

        $response = $this->withToken($token)
            ->getJson('/api/student/enrolled-sections');

        $response->assertOk()
            ->assertJsonStructure([
                'data' => [
                    '*' => [
                        'enrollment_id',
                        'section' => ['id', 'name', 'term'],
                        'course' => ['id', 'code', 'title', 'credit_hours'],
                        'primary_faculty_name',
                        'review_status',
                    ],
                ],
            ]);

        $firstSection = $response->json('data.0');
        $this->assertEquals('not_started', $firstSection['review_status']);
    }

    public function test_enrolled_sections_returns_no_active_window_status_when_none_active(): void
    {
        // Deactivate review windows
        ReviewWindow::query()->update(['status' => ReviewWindowStatus::Closed]);

        $token = $this->student->createToken('student_test')->plainTextToken;

        $response = $this->withToken($token)
            ->getJson('/api/student/enrolled-sections');

        $response->assertOk();
        $this->assertEquals('no_active_window', $response->json('data.0.review_status'));
    }

    // ── 4.2 GET /api/student/review-windows/active ─────────────────

    public function test_student_can_fetch_active_review_window(): void
    {
        $token = $this->student->createToken('student_test')->plainTextToken;

        $response = $this->withToken($token)
            ->getJson('/api/student/review-windows/active');

        $response->assertOk()
            ->assertJson([
                'data' => [
                    'id' => $this->activeWindow->id,
                    'title' => $this->activeWindow->title,
                    'status' => 'active',
                ],
            ]);
    }

    public function test_active_review_window_returns_null_when_none_active(): void
    {
        ReviewWindow::query()->update(['status' => ReviewWindowStatus::Closed]);

        $token = $this->student->createToken('student_test')->plainTextToken;

        $response = $this->withToken($token)
            ->getJson('/api/student/review-windows/active');

        $response->assertOk()
            ->assertJson([
                'data' => null,
                'message' => 'No active review window at this time.',
            ]);
    }

    // ── 4.3 GET /api/student/review-form ───────────────────────────

    public function test_review_form_validates_required_parameters(): void
    {
        $token = $this->student->createToken('student_test')->plainTextToken;

        $this->withToken($token)
            ->getJson('/api/student/review-form')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['review_window_id', 'section_id']);
    }

    public function test_review_form_blocks_non_active_window(): void
    {
        $draftWindow = ReviewWindow::create([
            'title' => 'Future Window',
            'starts_at' => now()->addMonth(),
            'ends_at' => now()->addMonths(2),
            'status' => ReviewWindowStatus::Draft,
        ]);

        $token = $this->student->createToken('student_test')->plainTextToken;

        $this->withToken($token)
            ->getJson("/api/student/review-form?review_window_id={$draftWindow->id}&section_id={$this->enrolledSection->id}")
            ->assertStatus(422)
            ->assertJson(['message' => 'The selected review window is not currently active or is outside the open submission date range.']);
    }

    public function test_review_form_blocks_unenrolled_section(): void
    {
        // Find or create section student is NOT enrolled in
        $enrolledSectionIds = StudentEnrollment::where('student_id', $this->student->id)->pluck('section_id');
        $unenrolledSection = Section::whereNotIn('id', $enrolledSectionIds)->first();

        $token = $this->student->createToken('student_test')->plainTextToken;

        $this->withToken($token)
            ->getJson("/api/student/review-form?review_window_id={$this->activeWindow->id}&section_id={$unenrolledSection->id}")
            ->assertForbidden()
            ->assertJson(['message' => 'Forbidden. You are not enrolled in this course section.']);
    }

    public function test_review_form_returns_active_questions_for_eligible_student(): void
    {
        $token = $this->student->createToken('student_test')->plainTextToken;

        $response = $this->withToken($token)
            ->getJson("/api/student/review-form?review_window_id={$this->activeWindow->id}&section_id={$this->enrolledSection->id}");

        $response->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'review_window_id',
                    'section_id',
                    'questions' => [
                        '*' => ['id', 'question_text', 'question_type', 'is_required', 'sort_order'],
                    ],
                ],
            ]);

        // Questions returned should only be active student_review questions
        $questions = $response->json('data.questions');
        $this->assertNotEmpty($questions);
    }

    public function test_review_form_blocks_student_if_already_submitted(): void
    {
        ReviewParticipation::create([
            'review_window_id' => $this->activeWindow->id,
            'section_id' => $this->enrolledSection->id,
            'student_id' => $this->student->id,
            'submitted_at' => now(),
        ]);

        $token = $this->student->createToken('student_test')->plainTextToken;

        $this->withToken($token)
            ->getJson("/api/student/review-form?review_window_id={$this->activeWindow->id}&section_id={$this->enrolledSection->id}")
            ->assertForbidden()
            ->assertJson(['message' => 'Forbidden. You have already submitted a review for this section in this review window.']);
    }

    // ── 4.4 POST /api/student/reviews ──────────────────────────────

    public function test_student_can_successfully_submit_anonymous_review(): void
    {
        $token = $this->student->createToken('student_test')->plainTextToken;

        // Build valid answers for active questions
        $activeQuestions = Question::where('form_type', FormType::StudentReview)
            ->where('is_active', true)
            ->get();

        $answersPayload = [];
        foreach ($activeQuestions as $q) {
            $value = match ($q->question_type) {
                QuestionType::Rating => 5,
                QuestionType::YesNo => true,
                QuestionType::Textarea => 'Great teacher and explanations.',
                default => 'Good',
            };
            $answersPayload[] = [
                'question_id' => $q->id,
                'value' => $value,
            ];
        }

        $payload = [
            'review_window_id' => $this->activeWindow->id,
            'section_id' => $this->enrolledSection->id,
            'answers' => $answersPayload,
        ];

        $response = $this->withToken($token)
            ->postJson('/api/student/reviews', $payload);

        $response->assertCreated()
            ->assertJsonStructure([
                'message',
                'data' => [
                    'pseudonym_token',
                ],
            ]);

        $pseudonymToken = $response->json('data.pseudonym_token');
        $this->assertTrue(Str::isUuid($pseudonymToken));

        // ── Anonymity & Isolation Verification ──
        // 1. review_responses must have the response with pseudonym_token and NO identity column
        $responseRow = ReviewResponse::where('pseudonym_token', $pseudonymToken)->first();
        $this->assertNotNull($responseRow);
        $this->assertEquals($this->activeWindow->id, $responseRow->review_window_id);
        $this->assertEquals($this->enrolledSection->id, $responseRow->section_id);

        // Verify Schema has NO student_id or user_id column
        $this->assertFalse(Schema::hasColumn('review_responses', 'student_id'));
        $this->assertFalse(Schema::hasColumn('review_responses', 'user_id'));

        // Verify Schema has NO created_at or updated_at columns (disabled Eloquent timestamps)
        $this->assertFalse(Schema::hasColumn('review_responses', 'created_at'));
        $this->assertFalse(Schema::hasColumn('review_responses', 'updated_at'));

        // Verify exact column listing on review_responses table
        $expectedColumns = ['id', 'review_window_id', 'section_id', 'pseudonym_token', 'answers_json', 'submitted_at'];
        $actualColumns = Schema::getColumnListing('review_responses');
        sort($expectedColumns);
        sort($actualColumns);
        $this->assertEquals($expectedColumns, $actualColumns);

        // Verify submitted_at timestamp on response is coarse (startOfDay) to prevent timing side-channel attacks
        $this->assertEquals(now()->startOfDay()->toDateTimeString(), $responseRow->submitted_at->toDateTimeString());

        // 2. review_participations must record that the student participated (NO answers)
        $participationRow = ReviewParticipation::where('review_window_id', $this->activeWindow->id)
            ->where('section_id', $this->enrolledSection->id)
            ->where('student_id', $this->student->id)
            ->first();
        $this->assertNotNull($participationRow);
        $this->assertFalse(Schema::hasColumn('review_participations', 'answers_json'));
        $this->assertFalse(Schema::hasColumn('review_participations', 'answers'));

        // 3. Status in enrolled-sections should now be 'submitted'
        $sectionsResponse = $this->withToken($token)
            ->getJson('/api/student/enrolled-sections');
        $submittedSection = collect($sectionsResponse->json('data'))->firstWhere('section.id', $this->enrolledSection->id);
        $this->assertEquals('submitted', $submittedSection['review_status']);
    }

    public function test_review_response_cannot_be_correlated_with_participation_via_precise_timestamp(): void
    {
        $testWindow = ReviewWindow::create([
            'title' => 'Timestamp Side-Channel Test Window',
            'starts_at' => now()->subDay(),
            'ends_at' => now()->addDays(7),
            'status' => ReviewWindowStatus::Active,
        ]);

        $section = $this->enrolledSection;
        $students = User::where('role', UserRole::Student)
            ->whereIn('id', StudentEnrollment::where('section_id', $section->id)->pluck('student_id'))
            ->take(3)
            ->get();

        $this->assertCount(3, $students);

        $activeQuestions = Question::where('form_type', FormType::StudentReview)
            ->where('is_active', true)
            ->get();

        $answersPayload = $activeQuestions->map(fn ($q) => [
            'question_id' => $q->id,
            'value' => $q->question_type === QuestionType::Rating ? 5 : true,
        ])->all();

        // 3 students submit reviews at distinct simulated times within the same day
        foreach ($students as $index => $stu) {
            Carbon::setTestNow(now()->startOfDay()->addHours(9 + $index)->addMinutes(15 * $index));

            $this->actingAs($stu, 'sanctum')
                ->postJson('/api/student/reviews', [
                    'review_window_id' => $testWindow->id,
                    'section_id' => $section->id,
                    'answers' => $answersPayload,
                ])
                ->assertCreated();
        }

        Carbon::setTestNow(); // reset mocked time

        // 1. Participations retain distinct, individual audit timestamps
        $participations = ReviewParticipation::where('review_window_id', $testWindow->id)
            ->where('section_id', $section->id)
            ->get();
        $this->assertCount(3, $participations);
        $this->assertCount(3, $participations->pluck('submitted_at')->unique());

        // 2. All responses collapse to identical coarse date (startOfDay), preventing timing correlation
        $responses = ReviewResponse::where('review_window_id', $testWindow->id)
            ->where('section_id', $section->id)
            ->get();
        $this->assertCount(3, $responses);
        $this->assertCount(1, $responses->pluck('submitted_at')->unique(), 'All responses on the same day must share identical coarse timestamp.');

        // 3. Confirm created_at and updated_at are not present on ReviewResponse records
        $this->assertFalse(isset($responses[0]->created_at));
        $this->assertFalse(isset($responses[0]->updated_at));

        // 4. A SQL query attempting to join participation and response on sub-day timestamp equality yields ZERO 1:1 correlations
        $exactTimeMatches = DB::table('review_participations as p')
            ->join('review_responses as r', function ($join) {
                $join->on('p.review_window_id', '=', 'r.review_window_id')
                    ->on('p.section_id', '=', 'r.section_id')
                    ->on('p.submitted_at', '=', 'r.submitted_at');
            })
            ->where('p.review_window_id', $testWindow->id)
            ->where('p.section_id', $section->id)
            ->get();

        $this->assertCount(0, $exactTimeMatches, 'No 1:1 de-anonymizing correlation is possible between participation and response via precise timestamps.');
    }

    public function test_duplicate_review_submission_is_prevented(): void
    {
        $token = $this->student->createToken('student_test')->plainTextToken;

        $activeQuestions = Question::where('form_type', FormType::StudentReview)
            ->where('is_active', true)
            ->get();

        $answersPayload = $activeQuestions->map(fn ($q) => [
            'question_id' => $q->id,
            'value' => $q->question_type === QuestionType::Rating ? 4 : true,
        ])->all();

        $payload = [
            'review_window_id' => $this->activeWindow->id,
            'section_id' => $this->enrolledSection->id,
            'answers' => $answersPayload,
        ];

        // First submission succeeds
        $this->withToken($token)
            ->postJson('/api/student/reviews', $payload)
            ->assertCreated();

        // Second submission for the same window + section is rejected
        $this->withToken($token)
            ->postJson('/api/student/reviews', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['review']);
    }

    public function test_submission_fails_when_required_question_is_missing(): void
    {
        $token = $this->student->createToken('student_test')->plainTextToken;

        // Omit answers
        $payload = [
            'review_window_id' => $this->activeWindow->id,
            'section_id' => $this->enrolledSection->id,
            'answers' => [
                ['question_id' => 9999, 'value' => 'invalid'],
            ],
        ];

        $this->withToken($token)
            ->postJson('/api/student/reviews', $payload)
            ->assertUnprocessable();
    }

    public function test_submission_fails_when_review_window_ends_at_has_passed(): void
    {
        // Update active window so ends_at is in the past, but status remains 'active'
        $this->activeWindow->update([
            'starts_at' => now()->subDays(10),
            'ends_at' => now()->subDay(),
            'status' => ReviewWindowStatus::Active,
        ]);

        $token = $this->student->createToken('student_test')->plainTextToken;

        $activeQuestions = Question::where('form_type', FormType::StudentReview)
            ->where('is_active', true)
            ->get();

        $answersPayload = $activeQuestions->map(fn ($q) => [
            'question_id' => $q->id,
            'value' => $q->question_type === QuestionType::Rating ? 5 : true,
        ])->all();

        $payload = [
            'review_window_id' => $this->activeWindow->id,
            'section_id' => $this->enrolledSection->id,
            'answers' => $answersPayload,
        ];

        // Submission must be rejected with validation error on review_window_id
        $this->withToken($token)
            ->postJson('/api/student/reviews', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['review_window_id']);

        // Form endpoint must also reject with 422
        $this->withToken($token)
            ->getJson("/api/student/review-form?review_window_id={$this->activeWindow->id}&section_id={$this->enrolledSection->id}")
            ->assertStatus(422)
            ->assertJson(['message' => 'The selected review window is not currently active or is outside the open submission date range.']);

        // Active window endpoint returns null
        $this->withToken($token)
            ->getJson('/api/student/review-windows/active')
            ->assertOk()
            ->assertJson(['data' => null]);
    }
}

