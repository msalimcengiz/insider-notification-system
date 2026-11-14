<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\Tracing\Tracing;
use Closure;
use Illuminate\Http\Request;
use OpenTelemetry\API\Trace\SpanInterface;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use Symfony\Component\HttpFoundation\Response;

/**
 * Server span for every HTTP request (the root of a notification's trace).
 * Continues the caller's trace when it sends a W3C `traceparent` header.
 */
final readonly class TraceHttpRequest
{
    /** Dashboard polling and docs assets would only add noise. */
    private const array IGNORED_PATHS = ['horizon', 'horizon/*', 'docs/*', '_scramble/*', 'up', 'vendor/*'];

    public function __construct(private Tracing $tracing) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->tracing->enabled() || $request->is(...self::IGNORED_PATHS)) {
            /** @var Response */
            return $next($request);
        }

        $parent = $this->tracing->extract(array_filter([
            'traceparent' => $request->header('traceparent'),
            'tracestate' => $request->header('tracestate'),
        ], 'is_string'));

        $method = $request->method() !== '' ? $request->method() : 'HTTP';

        return $this->tracing->inSpan($method, function (SpanInterface $span) use ($request, $next): Response {
            /** @var Response $response */
            $response = $next($request);

            $route = $request->route()?->uri();
            if (is_string($route)) {
                $span->updateName($request->method().' /'.ltrim($route, '/'));
                $span->setAttribute('http.route', '/'.ltrim($route, '/'));
            }

            $status = $response->getStatusCode();
            $span->setAttribute('http.response.status_code', $status);

            if ($status >= 500) {
                $span->setStatus(StatusCode::STATUS_ERROR);
            }

            return $response;
        }, [
            'http.request.method' => $request->method(),
            'url.path' => '/'.ltrim($request->path(), '/'),
            'url.scheme' => $request->getScheme(),
            'server.address' => $request->getHost(),
            'client.address' => $request->ip(),
            'user_agent.original' => $request->userAgent(),
        ], SpanKind::KIND_SERVER, $parent);
    }
}
