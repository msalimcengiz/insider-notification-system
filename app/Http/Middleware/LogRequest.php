<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * One structured log line per API request. The correlation ID is attached
 * automatically through the Laravel Context.
 */
final class LogRequest
{
    public function handle(Request $request, Closure $next): Response
    {
        $request->attributes->set('started_at', hrtime(true));

        /** @var Response */
        return $next($request);
    }

    public function terminate(Request $request, Response $response): void
    {
        $startedAt = $request->attributes->get('started_at');
        $status = $response->getStatusCode();

        Log::log($status >= 500 ? 'error' : 'info', 'http.request', [
            'method' => $request->method(),
            'path' => '/'.ltrim($request->path(), '/'),
            'route' => $request->route()?->uri(),
            'status' => $status,
            'duration_ms' => is_int($startedAt) || is_float($startedAt)
                ? round((hrtime(true) - $startedAt) / 1_000_000, 2)
                : null,
            'ip' => $request->ip(),
        ]);
    }
}
