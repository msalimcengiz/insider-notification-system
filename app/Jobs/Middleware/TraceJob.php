<?php

declare(strict_types=1);

namespace App\Jobs\Middleware;

use App\Jobs\SendNotification;
use App\Support\Tracing\Tracing;
use Closure;
use Illuminate\Support\Facades\Context;
use OpenTelemetry\API\Trace\SpanInterface;
use OpenTelemetry\API\Trace\SpanKind;

/**
 * Consumer span around everything a worker does with a job: the claim
 * pre-check, the circuit breaker, rate limit waiting, the provider call and
 * recording the result. Its parent is the producer span the job was
 * dispatched from, so API request → queue → worker → provider is one trace.
 */
final readonly class TraceJob
{
    public function __construct(private Tracing $tracing) {}

    public function handle(SendNotification $job, Closure $next): mixed
    {
        $queue = $job->job?->getQueue() ?? $job->queue ?? 'unknown';

        try {
            return $this->trace($job, $next, $queue);
        } finally {
            $this->tracing->flushIfDue();
        }
    }

    private function trace(SendNotification $job, Closure $next, string $queue): mixed
    {
        return $this->tracing->inSpan("process {$queue}", function (SpanInterface $span) use ($job, $next): mixed {
            // Lets log lines of this job be joined with the trace.
            $traceId = $span->getContext()->getTraceId();
            Context::add('trace_id', $traceId);

            $result = $next($job);

            $span->setAttribute('messaging.message.released', $job->job?->isReleased() ?? false);

            return $result;
        }, [
            'messaging.system' => 'redis',
            'messaging.operation.type' => 'process',
            'messaging.destination.name' => $queue,
            'messaging.message.id' => $job->job?->getJobId(),
            'messaging.message.delivery_count' => $job->job?->attempts(),
            'notification.id' => $job->notificationId,
            'notification.channel' => $job->channel->value,
        ], SpanKind::KIND_CONSUMER, $this->tracing->extract($job->traceContext));
    }
}
