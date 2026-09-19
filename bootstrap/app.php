<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Trust ONLY the immediate reverse proxy. On Azure App Service the
        // app runs behind the in-container nginx in front of php-fpm, whose
        // REMOTE_ADDR is the one hop genuinely forwarding X-Forwarded-*.
        // The previous at:'*' trusted every hop, letting any client spoof
        // X-Forwarded-For and reset rate-limit buckets at will.
        $middleware->trustProxies(at: 'REMOTE_ADDR');
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
