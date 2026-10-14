<?php

use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

require dirname(__DIR__, 2).'/vendor/autoload.php';

try {
    $app = require dirname(__DIR__, 2).'/bootstrap/app.php';
    $app->make(ConsoleKernel::class)->bootstrap();
    if (! $app->environment('testing') || DB::getDriverName() !== 'pgsql'
        || ! str_starts_with(DB::connection()->getDatabaseName(), 'fasre_test_')) {
        throw new RuntimeException('Worker requires an isolated PostgreSQL test database.');
    }

    $credentials = json_decode(stream_get_contents(STDIN), true, flags: JSON_THROW_ON_ERROR);
    echo json_encode(['ready_pid' => (int) DB::selectOne('SELECT pg_backend_pid() AS pid')->pid]).PHP_EOL;
    flush();

    $request = Request::create('/api/login', 'POST', $credentials, [], [], ['HTTP_ACCEPT' => 'application/json']);
    $response = $app->make(HttpKernel::class)->handle($request);
    $body = json_decode($response->getContent(), true, flags: JSON_THROW_ON_ERROR);
    $hasSession = isset($body['token']) || collect($response->headers->getCookies())
        ->contains(fn ($cookie) => $cookie->getName() === 'fasre_session');
    echo json_encode([
        'status' => $response->getStatusCode(),
        'mfa_required' => $body['mfa_required'] ?? false,
        'has_session' => $hasSession,
    ]).PHP_EOL;
} catch (Throwable $error) {
    fwrite(STDERR, 'Login worker failed: '.$error::class.PHP_EOL);
    exit(1);
}
