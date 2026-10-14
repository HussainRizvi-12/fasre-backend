<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Course;
use App\Models\Department;
use App\Models\FormVersion;
use App\Models\ReviewResponse;
use App\Models\ReviewWindow;
use App\Models\Section;
use App\Models\User;
use App\Services\ReviewAggregationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class ReviewResultsBatchingTest extends TestCase
{
    use RefreshDatabase;

    public function test_streamed_csv_queries_are_batched_and_preserve_section_filter(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'is_central_qa' => true, 'department_id' => null]);
        $department = Department::create(['name' => 'CSV batch', 'code' => 'CSV']);
        $course = Course::create(['department_id' => $department->id, 'code' => 'CSV101', 'title' => 'CSV']);
        $window = ReviewWindow::create(['title' => 'CSV cycle', 'term' => 'Fall 2026', 'starts_at' => now()->subDays(3), 'ends_at' => now()->subDay(), 'status' => 'closed']);
        $token = $admin->createToken('csv')->plainTextToken;
        $counts = [];
        foreach ([1, 50, 101] as $size) {
            while (Section::count() < $size) {
                Section::create(['course_id' => $course->id, 'name' => 'CSV-'.(Section::count() + 1), 'term' => 'Fall 2026', 'is_active' => true]);
            }
            DB::enableQueryLog();
            DB::flushQueryLog();
            try {
                $response = $this->withToken($token)->get('/api/admin/export/review-results?review_window_id='.$window->id)->assertOk();
                $csv = $response->streamedContent();
                $counts[] = count(DB::getQueryLog());
            } finally {
                DB::disableQueryLog();
                DB::flushQueryLog();
            }
            $this->assertCount($size + 1, array_filter(explode("\n", trim($csv))));
            $this->assertStringContainsString('(suppressed', $csv);
        }
        $this->assertLessThanOrEqual($counts[0] + 2, $counts[1]);
        $this->assertLessThanOrEqual($counts[1] + 7, $counts[2]);
        $first = Section::firstOrFail();
        $csv = $this->withToken($token)->get('/api/admin/export/review-results?review_window_id='.$window->id.'&section_id='.$first->id)->assertOk()->streamedContent();
        $this->assertCount(2, array_filter(explode("\n", trim($csv))));
        $this->assertStringNotContainsString('CSV-2,', $csv);
    }

    public function test_results_query_count_does_not_grow_per_section(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin, 'is_central_qa' => true, 'department_id' => null]);
        $department = Department::create(['name' => 'Batch department', 'code' => 'BAT', 'is_active' => true]);
        $course = Course::create(['department_id' => $department->id, 'code' => 'BAT101', 'title' => 'Batch course', 'is_active' => true]);
        $window = ReviewWindow::create(['title' => 'Batch cycle', 'term' => 'Fall 2026', 'starts_at' => now()->subDays(4), 'ends_at' => now()->subDay(), 'status' => 'closed']);
        $token = $admin->createToken('results-test')->plainTextToken;
        $created = 0;
        $counts = [];
        foreach ([1, 50, 101] as $size) {
            while ($created < $size) {
                Section::create(['course_id' => $course->id, 'name' => 'S'.(++$created), 'term' => 'Fall 2026', 'is_active' => true]);
            }
            DB::flushQueryLog();
            DB::enableQueryLog();
            try {
                $response = $this->withToken($token)->getJson('/api/admin/review-results?review_window_id='.$window->id);
                $counts[] = count(DB::getQueryLog());
            } finally {
                DB::disableQueryLog();
                DB::flushQueryLog();
            }
            $response->assertOk()->assertJsonCount($size, 'data')->assertJsonPath('data.0.is_suppressed', true);
        }
        $this->assertLessThanOrEqual($counts[0] + 2, $counts[1], 'Results still perform database work for each section.');
        $this->assertLessThanOrEqual($counts[1] + 1, $counts[2], 'Crossing a batch boundary should add only one response query.');
    }

    public function test_batching_retains_frozen_questions_scope_and_item_suppression(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin, 'is_central_qa' => true, 'department_id' => null]);
        $department = Department::create(['name' => 'Scoped batch', 'code' => 'SBT', 'is_active' => true]);
        $foreign = Department::create(['name' => 'Other batch', 'code' => 'OBT', 'is_active' => true]);
        $course = Course::create(['department_id' => $department->id, 'code' => 'SBT101', 'title' => 'Scoped course', 'is_active' => true]);
        $otherCourse = Course::create(['department_id' => $foreign->id, 'code' => 'OBT101', 'title' => 'Other course', 'is_active' => true]);
        $sections = [];
        foreach (['available', 'suppressed', 'unselected', 'foreign', 'wrong-term', 'inactive'] as $name) {
            $sections[$name] = Section::create([
                'course_id' => $name === 'foreign' ? $otherCourse->id : $course->id,
                'name' => $name, 'term' => $name === 'wrong-term' ? 'Spring 2027' : 'Fall 2026',
                'is_active' => $name !== 'inactive',
            ]);
        }
        $form = FormVersion::create([
            'form_type' => 'student_review', 'version_code' => 'batch-frozen', 'title' => 'Frozen batch form', 'is_published' => true,
            'questions_json' => [
                ['id' => 1, 'question_text' => 'Frozen rating', 'question_type' => 'rating', 'sort_order' => 1],
                ['id' => 2, 'question_text' => 'Frozen yes/no', 'question_type' => 'yes_no', 'sort_order' => 2],
                ['id' => 3, 'question_text' => 'Suppressed text', 'question_type' => 'text', 'sort_order' => 3],
                ['id' => 4, 'question_text' => 'Available text', 'question_type' => 'textarea', 'sort_order' => 4],
            ],
        ]);
        $window = ReviewWindow::create(['title' => 'Frozen scoped cycle', 'term' => 'Fall 2026', 'department_id' => $department->id, 'form_version_id' => $form->id, 'starts_at' => now()->subDays(4), 'ends_at' => now()->subDay(), 'status' => 'closed']);
        $window->sections()->sync(collect($sections)->except('unselected')->pluck('id')->all());
        foreach (['available' => 5, 'suppressed' => 4] as $name => $count) {
            for ($i = 0; $i < $count; $i++) {
                ReviewResponse::create(['review_window_id' => $window->id, 'section_id' => $sections[$name]->id, 'pseudonym_token' => (string) Str::uuid(), 'submitted_at' => now()->startOfDay(), 'answers_json' => [1 => 4, 2 => $i < 3, 3 => $i < 4 ? 'Private optional '.$i : null, 4 => 'Visible '.$i]]);
            }
        }
        $response = $this->withToken($admin->createToken('batch-scope')->plainTextToken)
            ->getJson('/api/admin/review-results?review_window_id='.$window->id)->assertOk()->assertJsonCount(2, 'data');
        $data = collect($response->json('data'))->keyBy('section_name');
        $this->assertSame(5, $data['available']['response_count']);
        $questions = collect($data['available']['questions'])->keyBy('question_id');
        $this->assertSame('Frozen rating', $questions[1]['question_text']);
        $this->assertEquals(4, $questions[1]['average']);
        $this->assertEquals(60, $questions[2]['percentage_yes']);
        $this->assertTrue($questions[3]['is_suppressed']);
        $this->assertArrayNotHasKey('responses', $questions[3]);
        $this->assertCount(5, $questions[4]['responses']);
        $this->assertTrue($data['suppressed']['is_suppressed']);
        $this->assertSame([], $data['suppressed']['questions']);

        $csv = $this->withToken($admin->createToken('csv-suppression')->plainTextToken)
            ->get('/api/admin/export/review-results?review_window_id='.$window->id)->assertOk()->streamedContent();
        $this->assertStringContainsString('fewer than 5 answers', $csv);
        $this->assertStringNotContainsString('Private optional', $csv);
        $this->assertStringNotContainsString('Visible 0', $csv);

        $definitions = collect($form->getQuestions())->map(fn ($question) => (object) $question);
        $service = app(ReviewAggregationService::class);
        $batch = $service->aggregateSections($window->id, [$sections['available']->id, $sections['suppressed']->id], $definitions);
        $this->assertSame($service->aggregateSection($window->id, $sections['available']->id, $definitions), $batch[$sections['available']->id]);
        $this->assertArrayNotHasKey('responses', $batch[$sections['available']->id]['questions'][3]);
    }
}
