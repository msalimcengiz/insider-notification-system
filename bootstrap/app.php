<?php

declare(strict_types=1);

use App\Exceptions\IdempotencyKeyConflict;
use App\Http\Middleware\AssignCorrelationId;
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
        $middleware->api(prepend: [
            AssignCorrelationId::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Client errors: rendered as 409, not worth an error log entry.
        $exceptions->dontReport(IdempotencyKeyConflict::class);

        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
