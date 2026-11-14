<?php

declare(strict_types=1);

namespace App\Support\Tracing;

use Closure;
use OpenTelemetry\API\Trace\Span;
use OpenTelemetry\API\Trace\SpanInterface;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\API\Trace\TracerInterface;
use OpenTelemetry\API\Trace\TracerProviderInterface;
use OpenTelemetry\Context\Context;
use OpenTelemetry\Context\ContextInterface;
use OpenTelemetry\Context\Propagation\TextMapPropagatorInterface;
use Throwable;

/**
 * Thin wrapper around the OpenTelemetry API. All spans of this application
 * are created explicitly (HTTP requests, SQL queries, queue pushes, job
 * processing, provider calls), so every hop is covered and testable without
 * a PHP extension.
 *
 * When the SDK is not enabled the tracer is a no-op and every call is cheap.
 */
final class Tracing
{
    private const int FLUSH_INTERVAL_SECONDS = 2;

    /** After a failed or slow export (collector down), stop flushing from the worker loop for this long. */
    private const int FLUSH_BACKOFF_SECONDS = 60;

    /**
     * A healthy OTLP export takes a few milliseconds. The SDK reports success
     * even when every retry failed, so a slow export is the reliable sign
     * that the collector is unreachable.
     */
    private const float SLOW_EXPORT_SECONDS = 0.25;

    private readonly TracerInterface $tracer;

    private float $nextFlushAt = 0.0;

    public function __construct(
        private readonly TracerProviderInterface $tracerProvider,
        private readonly TextMapPropagatorInterface $propagator,
    ) {
        $this->tracer = $tracerProvider->getTracer('insider-notification-system');
    }

    public function enabled(): bool
    {
        return $this->tracer->isEnabled();
    }

    /**
     * Whether a span is active, i.e. new spans would join an existing trace.
     */
    public function hasActiveSpan(): bool
    {
        return Span::getCurrent()->getContext()->isValid();
    }

    /**
     * Records an operation that already finished, e.g. a SQL query reported
     * by Laravel after it ran, as a child of the active span.
     *
     * @param  non-empty-string  $name
     * @param  array<string, scalar|null>  $attributes
     * @param  SpanKind::KIND_*  $kind
     */
    public function recordCompleted(string $name, float $durationMs, array $attributes = [], int $kind = SpanKind::KIND_CLIENT): void
    {
        $endNanos = (int) (microtime(true) * 1_000_000_000);
        $startNanos = $endNanos - (int) ($durationMs * 1_000_000);

        $this->tracer->spanBuilder($name)
            ->setSpanKind($kind)
            ->setStartTimestamp($startNanos)
            ->setAttributes(array_filter($attributes, fn (mixed $value): bool => $value !== null))
            ->startSpan()
            ->end($endNanos);
    }

    /**
     * Exports buffered spans if the last export is a while ago. Long-running
     * workers call this after each job so the spans of a lone job show up
     * right away instead of waiting for the next batch.
     *
     * Tracing must never slow delivery down: when an export fails (the
     * collector is down) the worker stops flushing for a minute, and the
     * exporter's own timeout is kept short (OTEL_EXPORTER_OTLP_TIMEOUT).
     */
    public function flushIfDue(): void
    {
        $now = microtime(true);

        if ($now < $this->nextFlushAt || ! method_exists($this->tracerProvider, 'forceFlush')) {
            return;
        }

        $exported = (bool) $this->tracerProvider->forceFlush();
        $healthy = $exported && microtime(true) - $now < self::SLOW_EXPORT_SECONDS;

        $this->nextFlushAt = $now + ($healthy ? self::FLUSH_INTERVAL_SECONDS : self::FLUSH_BACKOFF_SECONDS);
    }

    /**
     * Runs $callback inside a new span (active for the duration of the call).
     * Exceptions are recorded on the span and rethrown.
     *
     * @template T
     *
     * @param  non-empty-string  $name
     * @param  Closure(SpanInterface): T  $callback
     * @param  array<string, scalar|null>  $attributes
     * @param  SpanKind::KIND_*  $kind
     * @return T
     */
    public function inSpan(
        string $name,
        Closure $callback,
        array $attributes = [],
        int $kind = SpanKind::KIND_INTERNAL,
        ?ContextInterface $parent = null,
    ): mixed {
        $builder = $this->tracer->spanBuilder($name)->setSpanKind($kind)->setAttributes(array_filter(
            $attributes,
            fn (mixed $value): bool => $value !== null,
        ));

        if ($parent !== null) {
            $builder->setParent($parent);
        }

        $span = $builder->startSpan();
        $scope = $span->storeInContext($parent ?? Context::getCurrent())->activate();

        try {
            return $callback($span);
        } catch (Throwable $e) {
            $span->recordException($e);
            $span->setStatus(StatusCode::STATUS_ERROR, $e->getMessage());

            throw $e;
        } finally {
            $scope->detach();
            $span->end();
        }
    }

    /**
     * The current trace context as W3C headers (`traceparent`, `tracestate`).
     *
     * @return array<string, string>
     */
    public function currentCarrier(): array
    {
        $carrier = [];
        $this->propagator->inject($carrier);

        return $carrier;
    }

    /**
     * @param  array<string, string>  $carrier
     */
    public function extract(array $carrier): ContextInterface
    {
        return $this->propagator->extract($carrier);
    }

    /**
     * Trace ID of the active span, or null when nothing is being traced.
     */
    public function currentTraceId(): ?string
    {
        $context = Span::getCurrent()->getContext();

        return $context->isValid() ? $context->getTraceId() : null;
    }
}
