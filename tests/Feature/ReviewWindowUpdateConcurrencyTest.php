<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\Department;
use App\Models\Question;
use App\Models\ReviewWindow;
use App\Models\Section;
use App\Models\StudentEnrollment;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class ReviewWindowUpdateConcurrencyTest extends TestCase
{
    use DatabaseMigrations;

    protected function beforeRefreshingDatabase(): void
    {
        if (! $this->app->environment('testing')) {
            throw new \RuntimeException('Testing environment required.');
        }
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Independent row-lock races require PostgreSQL.');
        }
        $this->assertStringStartsWith('fasre_test_', DB::connection()->getDatabaseName());
    }

    public static function changes(): array
    {
        return [['activation'], ['partial-dates'], ['department']];
    }

    #[DataProvider('changes')]
    public function test_waiting_patch_revalidates_committed_state(string $change): void
    {
        $department = Department::create(['name' => 'First', 'code' => 'ONE']);
        $other = Department::create(['name' => 'Second', 'code' => 'TWO']);
        $admin = User::factory()->create(['role' => 'admin', 'is_central_qa' => true, 'department_id' => null]);
        $caller = $change === 'department' ? User::factory()->create(['role' => 'admin', 'is_central_qa' => false, 'department_id' => $department->id]) : $admin;
        $actorToken = $admin->createToken('actor')->plainTextToken;
        $callerToken = $caller->createToken('caller')->plainTextToken;
        $course = Course::create(['department_id' => $department->id, 'code' => 'ONE101', 'title' => 'One']);
        $section = Section::create(['course_id' => $course->id, 'name' => 'A', 'term' => 'Fall 2026', 'is_active' => true]);
        $student = User::factory()->create(['role' => 'student', 'department_id' => $department->id]);
        StudentEnrollment::create(['student_id' => $student->id, 'section_id' => $section->id]);
        Question::create(['form_type' => 'student_review', 'question_type' => 'rating', 'question_text' => 'Teaching', 'is_active' => true, 'is_required' => true, 'sort_order' => 1]);
        $window = ReviewWindow::create(['title' => 'Concurrent draft', 'department_id' => $department->id, 'term' => 'Fall 2026',
            'starts_at' => $change === 'partial-dates' ? now()->addDay() : now()->subDay(), 'ends_at' => now()->addDays(3), 'status' => 'draft']);
        $patch = $change === 'partial-dates' ? ['ends_at' => now()->addDays(2)->toIso8601String()] : ['term' => 'Spring 2027'];
        $config = DB::connection()->getConfig();
        $child = new Process([PHP_BINARY, base_path('tests/Support/window-update-worker.php')], base_path(), [
            'APP_ENV' => 'testing', 'APP_KEY' => config('app.key'), 'DB_CONNECTION' => 'pgsql', 'DB_URL' => '',
            'DB_HOST' => $config['host'], 'DB_PORT' => (string) $config['port'], 'DB_DATABASE' => $config['database'],
            'DB_USERNAME' => $config['username'], 'DB_PASSWORD' => $config['password'] ?? '', 'CACHE_STORE' => 'array', 'MAIL_MAILER' => 'array', 'QUEUE_CONNECTION' => 'sync',
        ]);
        $child->setInput(json_encode(['window' => $window->id, 'patch' => $patch, 'token' => $callerToken]));
        $child->setTimeout(20);
        DB::beginTransaction();
        try {
            ReviewWindow::whereKey($window->id)->lockForUpdate()->firstOrFail();
            $child->start();
            $blocked = false;
            $deadline = microtime(true) + 10;
            while (microtime(true) < $deadline && $child->isRunning()) {
                $ready = json_decode(strtok($child->getOutput(), "\n") ?: '{}', true);
                if (isset($ready['pid'])) {
                    DB::select('SELECT pg_stat_clear_snapshot()');
                    if (DB::selectOne('SELECT wait_event_type FROM pg_stat_activity WHERE pid=?', [$ready['pid']])?->wait_event_type === 'Lock') {
                        $blocked = true;
                        break;
                    }
                }
                usleep(20_000);
            }
            $this->assertTrue($blocked, 'Update did not wait for the competing transition.');
            if ($change === 'activation') {
                $this->withToken($actorToken)->postJson('/api/admin/review-windows/'.$window->id.'/activate')->assertOk();
            } else {
                $this->withToken($actorToken)->patchJson('/api/admin/review-windows/'.$window->id, $change === 'department' ? ['department_id' => $other->id] : ['starts_at' => now()->addDays(2)->addHours(6)->toIso8601String()])->assertOk();
            }
            DB::commit();
            $child->wait();
            $this->assertTrue($child->isSuccessful(), $child->getErrorOutput());
            $lines = array_values(array_filter(explode("\n", trim($child->getOutput()))));
            $result = json_decode(end($lines), true, flags: JSON_THROW_ON_ERROR);
            $this->assertSame($change === 'department' ? 403 : 422, $result['status']);
            $window->refresh();
            $this->assertSame('Fall 2026', $window->term);
            if ($change === 'activation') {
                $this->assertTrue($window->roster_is_frozen);
                $this->assertTrue($window->isStudentEligible($student->id, $section->id));
            } elseif ($change === 'partial-dates') {
                $this->assertTrue($window->ends_at->greaterThan($window->starts_at));
            } else {
                $this->assertSame($other->id, $window->department_id);
            }
        } finally {
            if (DB::transactionLevel()) {
                DB::rollBack();
            } if ($child->isRunning()) {
                $child->stop();
            }
        }
    }
}
