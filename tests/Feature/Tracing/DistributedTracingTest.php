<?php

declare(strict_types=1);

use App\Actions\DeliverNotification;
use App\Enums\Channel;
use App\Enums\NotificationStatus;
use App\Enums\Priority;
use App\Jobs\SendNotification;
use App\Models\Notification;
use App\Providers\AppServiceProvider;
use App\Services\Delivery\NotificationDispatcher;
use App\Services\Delivery\ProviderRegistry;
use App\Services\Delivery\RetryPolicy;
use App\Support\Tracing\Tracing;
use Illuminate\Http\Client\Request;
use Illuminate\Pipeline\Pipeline;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use OpenTelemetry\API\Trace\NoopTracerProvider;
use OpenTelemetry\API\Trace\Propagation\TraceContextPropagator;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\SDK\Trace\ImmutableSpan;
use OpenTelemetry\SDK\Trace\SpanExporter\InMemoryExporter;
use OpenTelemetry\SDK\Trace\SpanProcessor\SimpleSpanProcessor;
use OpenTelemetry\SDK\Trace\TracerProvider;

beforeEach(function (): void {
    $this->flushRedis();
    $this->exporter = new InMemoryExporter;
    $this->tracerProvider = new TracerProvider(new SimpleSpanProcessor($this->exporter));
    $this->app->instance(Tracing::class, new Tracing($this->tracerProvider, TraceContextPropagator::getInstance()));
    $this->app->forgetInstance(ProviderRegistry::class);
    $this->app->instance(RetryPolicy::class, new RetryPolicy(3, 10, 3, 900, 0.0));
});

/**
 * @return array<string, ImmutableSpan>
 */
function spansByName(InMemoryExporter $exporter): array
{
    $spans = [];
    foreach ($exporter->getSpans() as $span) {
        $spans[$span->getName()] = $span;
    }

    return $spans;
}

it('carries one trace from dispatch through the worker to the provider call', function (): void {
    Queue::fake();
    Http::fake(['*' => Http::response(['messageId' => 'm-1', 'status' => 'accepted'], 202)]);
    $notification = Notification::factory()->priority(Priority::High)->create();

    // API side: dispatching creates a producer span and stamps the job.
    app(NotificationDispatcher::class)->dispatch([$notification]);

    $pushed = null;
    Queue::assertPushed(SendNotification::class, function (SendNotification $job) use (&$pushed): bool {
        $pushed = $job;

        return true;
    });
    expect($pushed->traceContext)->toHaveKey('traceparent');

    // Worker side: run the job through its middleware like Horizon would.
    $job = $pushed->withFakeQueueInteractions();
    app(Pipeline::class)
        ->send($job)
        ->through($job->middleware())
        ->then(fn (SendNotification $j) => app()->call([$j, 'handle']));

    $this->tracerProvider->forceFlush();
    $spans = spansByName($this->exporter);

    $send = $spans['send notifications-sms-high'];
    $process = collect($spans)->first(fn (ImmutableSpan $s): bool => str_starts_with($s->getName(), 'process'));
    $http = $spans['POST provider.test'];

    // Same trace, linked parent → child.
    expect($send->getKind())->toBe(SpanKind::KIND_PRODUCER)
        ->and($process->getKind())->toBe(SpanKind::KIND_CONSUMER)
        ->and($http->getKind())->toBe(SpanKind::KIND_CLIENT)
        ->and($process->getTraceId())->toBe($send->getTraceId())
        ->and($http->getTraceId())->toBe($send->getTraceId())
        ->and($process->getParentSpanId())->toBe($send->getSpanId())
        ->and($http->getParentSpanId())->toBe($process->getSpanId());

    expect($send->getAttributes()->get('messaging.destination.name'))->toBe('notifications-sms-high')
        ->and($process->getAttributes()->get('notification.id'))->toBe($notification->id)
        ->and($process->getAttributes()->get('notification.status'))->toBe('sent')
        ->and($http->getAttributes()->get('http.response.status_code'))->toBe(202)
        ->and($http->getAttributes()->get('provider.message_id'))->toBe('m-1');

    // The provider receives the trace context as a header, the body is unchanged.
    Http::assertSent(fn (Request $request): bool => $request->hasHeader('traceparent')
        && str_contains($request->header('traceparent')[0], $send->getTraceId())
        && $request->data() === ['to' => $notification->recipient, 'channel' => 'sms', 'content' => $notification->content]);
});

it('marks failed provider calls as errors', function (): void {
    Http::fake(['*' => Http::response(['message' => 'down'], 503)]);
    $notification = Notification::factory()->status(NotificationStatus::Queued)->create();

    app(DeliverNotification::class)->handle($notification->id);
    $this->tracerProvider->forceFlush();

    $http = spansByName($this->exporter)['POST provider.test'];

    expect($http->getStatus()->getCode())->toBe('Error')
        ->and($http->getAttributes()->get('error.type'))->toBe('503')
        ->and($http->getAttributes()->get('notification.outcome'))->toBe('retryable_failure');
});

it('records rate limit waiting on the job span', function (): void {
    config(['notifications.rate_limit.per_second' => 1]);
    Http::fake(['*' => Http::response(['messageId' => 'm'], 202)]);
    $first = Notification::factory()->status(NotificationStatus::Queued)->create();
    $second = Notification::factory()->status(NotificationStatus::Queued)->create();

    foreach ([$first, $second] as $notification) {
        $job = (new SendNotification($notification->id, Channel::Sms))->withFakeQueueInteractions();
        app(Pipeline::class)->send($job)->through($job->middleware())->then(fn ($j) => app()->call([$j, 'handle']));
    }
    $this->tracerProvider->forceFlush();

    $processSpans = array_values(array_filter($this->exporter->getSpans(), fn (ImmutableSpan $s): bool => str_starts_with($s->getName(), 'process')));
    $events = array_map(fn ($e) => $e->getName(), $processSpans[1]->getEvents());

    expect($events)->toContain('rate_limit.waited');
});

it('starts a new trace for jobs dispatched without one', function (): void {
    $job = new SendNotification('id', Channel::Email);

    expect($job->traceContext)->toBe([]);
});

it('returns the trace ID to API clients when tracing is on', function (): void {
    Queue::fake();
    $tracing = app(Tracing::class);

    $response = $tracing->inSpan('POST', fn () => $this->postJson('/api/v1/notifications', [
        'channel' => 'sms', 'recipient' => '+905551234567', 'content' => 'Hi',
    ]));

    expect($response->headers->get('X-Trace-Id'))->toMatch('/^[0-9a-f]{32}$/');
});

it('adds nothing when tracing is off', function (): void {
    Queue::fake();
    $this->app->instance(Tracing::class, new Tracing(new NoopTracerProvider, TraceContextPropagator::getInstance()));

    $this->postJson('/api/v1/notifications', ['channel' => 'sms', 'recipient' => '+905551234567', 'content' => 'Hi'])
        ->assertAccepted()
        ->assertHeaderMissing('X-Trace-Id');
});

it('creates a server span per API request named after the route', function (): void {
    Queue::fake();

    $this->postJson('/api/v1/notifications', ['channel' => 'sms', 'recipient' => '+905551234567', 'content' => 'Hi'])
        ->assertAccepted();
    $this->tracerProvider->forceFlush();

    $server = spansByName($this->exporter)['POST /api/v1/notifications'];

    expect($server->getKind())->toBe(SpanKind::KIND_SERVER)
        ->and($server->getAttributes()->get('http.route'))->toBe('/api/v1/notifications')
        ->and($server->getAttributes()->get('http.response.status_code'))->toBe(202)
        ->and($server->getAttributes()->get('correlation.id'))->toBeString();
});

it('continues the caller\'s trace from a traceparent header', function (): void {
    Queue::fake();
    $traceId = '4bf92f3577b34da6a3ce929d0e0e4736';

    $this->postJson('/api/v1/notifications', ['channel' => 'sms', 'recipient' => '+905551234567', 'content' => 'Hi'], [
        'traceparent' => "00-{$traceId}-00f067aa0ba902b7-01",
    ])->assertHeader('X-Trace-Id', $traceId);
});

it('marks 5xx responses as errors and ignores dashboard polling', function (): void {
    $this->app['router']->get('/api/v1/boom', fn () => abort(503));

    $this->getJson('/api/v1/boom')->assertStatus(503);
    $this->get('/up');
    $this->tracerProvider->forceFlush();

    $names = array_keys(spansByName($this->exporter));

    expect(spansByName($this->exporter)['GET /api/v1/boom']->getStatus()->getCode())->toBe('Error')
        ->and($names)->not->toContain('GET /up');
});

it('records SQL queries as child spans without their bindings', function (): void {
    Queue::fake();
    // The listener is registered at boot, with the tracer of that moment.
    $this->refreshApplication();
    $this->app->instance(Tracing::class, new Tracing($this->tracerProvider, TraceContextPropagator::getInstance()));
    (new AppServiceProvider($this->app))->boot();

    app(Tracing::class)->inSpan('parent', fn () => Notification::query()->where('recipient', '+905550000000')->exists());
    Notification::query()->count(); // outside any trace: not recorded
    $this->tracerProvider->forceFlush();

    $sql = array_values(array_filter($this->exporter->getSpans(), fn (ImmutableSpan $s): bool => $s->getName() === 'SELECT notifications'));

    expect($sql)->toHaveCount(1)
        ->and($sql[0]->getAttributes()->get('db.system.name'))->toBe('postgresql')
        ->and($sql[0]->getAttributes()->get('db.query.text'))->not->toContain('+905550000000')
        ->and($sql[0]->getParentSpanId())->toBe(spansByName($this->exporter)['parent']->getSpanId());
});

it('traces scheduler runs only when there is work', function (): void {
    Queue::fake();

    $this->artisan('notifications:dispatch-scheduled')->assertSuccessful();
    $this->artisan('notifications:recover')->assertSuccessful();
    $this->tracerProvider->forceFlush();
    expect($this->exporter->getSpans())->toBe([]);

    Notification::factory()->scheduledAt(now()->subSecond())->create();
    $this->artisan('notifications:dispatch-scheduled')->assertSuccessful();
    $this->tracerProvider->forceFlush();

    $spans = spansByName($this->exporter);
    expect($spans)->toHaveKey('notifications:dispatch-scheduled')
        ->and($spans['send notifications-sms-normal']->getParentSpanId())
        ->toBe($spans['notifications:dispatch-scheduled']->getSpanId());
});
