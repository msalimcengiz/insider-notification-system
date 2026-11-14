<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Reuses the caller's X-Correlation-ID (or generates one) and puts it in the
 * Laravel Context. Context is attached to every log entry and is serialised
 * into queued jobs, so the same ID follows a request from the API through
 * the workers to the provider call.
 */
final class AssignCorrelationId
{
    public const string HEADER = 'X-Correlation-ID';

    public const string CONTEXT_KEY = 'correlation_id';

    public function handle(Request $request, Closure $next): Response
    {
        $correlationId = $this->fromRequest($request) ?? (string) Str::uuid7();

        Context::add(self::CONTEXT_KEY, $correlationId);

        /** @var Response $response */
        $response = $next($request);
        $response->headers->set(self::HEADER, $correlationId);

        return $response;
    }

    private function fromRequest(Request $request): ?string
    {
        $value = $request->headers->get(self::HEADER);

        // Ignore anything that could pollute logs: only short, plain tokens.
        if (is_string($value) && preg_match('/^[A-Za-z0-9._\-]{1,64}$/', $value) === 1) {
            return $value;
        }

        return null;
    }

    public static function current(): ?string
    {
        $value = Context::get(self::CONTEXT_KEY);

        return is_string($value) ? $value : null;
    }
}
