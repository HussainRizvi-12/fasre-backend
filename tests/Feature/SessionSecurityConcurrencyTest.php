<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\User;
use App\Services\MfaService;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class SessionSecurityConcurrencyTest extends TestCase
{
    use DatabaseMigrations;

    protected function beforeRefreshingDatabase(): void
    {
        // Check the resolved target before DatabaseMigrations can drop tables.
        if (! $this->app->environment('testing')) {
            throw new \RuntimeException('Concurrency verification requires the testing environment.');
        }
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Independent row-lock verification requires PostgreSQL.');
        }
        $this->assertStringStartsWith('fasre_test_', DB::connection()->getDatabaseName());
    }

    public static function securityChanges(): array
    {
        return [['password-reset'], ['deactivation'], ['mfa-enrollment']];
    }

    #[DataProvider('securityChanges')]
    public function test_login_waits_for_security_change_and_checks_committed_state(string $change): void
    {
        $user = User::factory()->create([
            'role' => $change === 'mfa-enrollment' ? UserRole::Admin : UserRole::Faculty,
            'is_central_qa' => $change === 'mfa-enrollment',
            'department_id' => null,
            'is_active' => true,
            'password' => Hash::make('OriginalPassword123!'),
        ]);
        $resetToken = $change === 'password-reset' ? Password::createToken($user) : null;
        $actor = User::factory()->create(['role' => UserRole::Admin, 'is_central_qa' => true, 'department_id' => null]);
        $actorToken = ($change === 'mfa-enrollment' ? $user : $actor)->createToken('admin')->plainTextToken;
        $connection = DB::connection()->getConfig();
        $process = new Process([PHP_BINARY, base_path('tests/Support/login-race-worker.php')], base_path(), [
            'APP_ENV' => 'testing', 'APP_KEY' => config('app.key'), 'DB_CONNECTION' => 'pgsql', 'DB_URL' => '',
            'DB_HOST' => $connection['host'], 'DB_PORT' => (string) $connection['port'],
            'DB_DATABASE' => $connection['database'], 'DB_USERNAME' => $connection['username'],
            'DB_PASSWORD' => $connection['password'] ?? '', 'DB_SSLMODE' => $connection['sslmode'] ?? 'prefer',
            'BCRYPT_ROUNDS' => '4', 'CACHE_STORE' => 'array', 'MAIL_MAILER' => 'array', 'QUEUE_CONNECTION' => 'sync',
        ]);
        $process->setInput(json_encode(['email' => $user->email, 'password' => 'OriginalPassword123!']));
        $process->setTimeout(20);

        DB::beginTransaction();
        try {
            User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            if ($change === 'password-reset') {
                $this->postJson('/api/reset-password', [
                    'email' => $user->email, 'token' => $resetToken, 'password' => 'ReplacementPassword123!',
                ])->assertOk();
            } elseif ($change === 'deactivation') {
                $this->withToken($actorToken)->patchJson('/api/admin/users/'.$user->id, ['is_active' => false])->assertOk();
            } else {
                $setup = $this->withToken($actorToken)->postJson('/api/admin/mfa/setup')->assertOk();
                $this->withToken($actorToken)->postJson('/api/admin/mfa/confirm', [
                    'code' => MfaService::calculateTotp($setup->json('secret')),
                ])->assertOk();
            }

            $process->start();
            $deadline = microtime(true) + 10;
            $blocked = false;
            while (microtime(true) < $deadline && $process->isRunning()) {
                $line = strtok($process->getOutput(), "\n");
                $ready = $line ? json_decode($line, true) : null;
                if (isset($ready['ready_pid'])) {
                    DB::select('SELECT pg_stat_clear_snapshot()');
                    $state = DB::selectOne('SELECT wait_event_type FROM pg_stat_activity WHERE pid = ?', [$ready['ready_pid']]);
                    if ($state?->wait_event_type === 'Lock') {
                        $blocked = true;
                        break;
                    }
                }
                usleep(20_000);
            }
            $this->assertTrue($blocked, 'Login completed or read stale credentials while the security update was uncommitted.');
            DB::commit();
            $process->wait();
            $this->assertTrue($process->isSuccessful(), $process->getErrorOutput());
            $lines = array_values(array_filter(explode("\n", trim($process->getOutput()))));
            $result = json_decode(end($lines), true, flags: JSON_THROW_ON_ERROR);
            $this->assertFalse($result['has_session']);
            if ($change === 'mfa-enrollment') {
                $this->assertSame(200, $result['status']);
                $this->assertTrue($result['mfa_required']);
            } else {
                $this->assertSame(401, $result['status']);
            }
        } finally {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            if ($process->isRunning()) {
                $process->stop();
            }
        }
    }
}
