<?php

use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

require dirname(__DIR__, 2).'/vendor/autoload.php';
try {
    $app = require dirname(__DIR__, 2).'/bootstrap/app.php';
    $app->make(ConsoleKernel::class)->bootstrap();
    if (! $app->environment('testing') || DB::getDriverName() !== 'pgsql' || ! str_starts_with(DB::connection()->getDatabaseName(), 'fasre_test_')) {
        throw new RuntimeException('Disposable PostgreSQL database required.');
    }
    $input = json_decode(stream_get_contents(STDIN), true, flags: JSON_THROW_ON_ERROR);
    echo json_encode(['pid' => (int) DB::selectOne('SELECT pg_backend_pid() AS pid')->pid]).PHP_EOL;
    flush();
    $request = Request::create('/api/admin/review-windows/'.$input['window'], 'PATCH', $input['patch'], [], [], ['HTTP_ACCEPT' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer '.$input['token']]);
    $response = $app->make(HttpKernel::class)->handle($request);
    echo json_encode(['status' => $response->getStatusCode()]).PHP_EOL;
} catch (Throwable $error) {
    fwrite(STDERR, 'Window worker failed: '.$error::class.PHP_EOL);
    exit(1);
}
