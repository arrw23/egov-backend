<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->alias([
            'role' => \App\Http\Middleware\EnsureRole::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        // This service only speaks JSON. Left to the default, a request whose
        // Accept header is not application/json took the authentication
        // handler's redirect branch, which resolved route('login') — a route
        // this app never defines — turning an honest 401 into a 500.
        // Note this binds to the framework handler; while APP_DEBUG=true,
        // Collision rebinds ExceptionHandler and shadows it. Ship with
        // APP_DEBUG=false.
        $exceptions->shouldRenderJsonWhen(fn () => true);
    })->create();
