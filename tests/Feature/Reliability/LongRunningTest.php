<?php

declare(strict_types=1);

use App\Actions\CreateNotificationBatch;
use App\DTOs\NotificationData;
use App\Enums\AttemptOutcome;
use App\Enums\Channel;
use App\Enums\NotificationStatus;
use App\Enums\Priority;
use App\Jobs\SendNotification;
use App\Models\Notification;
use App\Models\NotificationAttempt;
use App\Models\NotificationBatch;
use App\Services\Delivery\ChannelProvider;
use App\Services\Delivery\DeliveryResult;
use App\Services\Delivery\ProviderRegistry;
use App\Support\Tracing\Tracing;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Pipeline\Pipeline;
use OpenTelemetry\API\Trace\NoopTracerProvider;
use OpenTelemetry\API\Trace\Propagation\TraceContextPropagator;
use OpenTelemetry\API\Trace\TracerInterface;
use OpenTelemetry\API\Trace\TracerProviderInterface;

/*
 * The process-level guarantees a server that runs for months depends on:
 * no memory growth per job or per request, no unbounded data, no lock or
 * dependency outage that can stall delivery.
 */

function acceptingProvider(): ChannelProvider
{
    return new class implements ChannelProvider
    {
        public function send(Notification $notification): DeliveryResult
        {
            return new DeliveryResult(AttemptOutcome::Success, 1, 202, 'm-'.$notification->id);
        }
    };
}

/**
 * Runs one job exactly like a Horizon worker: middleware, then handle.
 */
function runJob(SendNotification $job): void
{
    app(Pipeline::class)->send($job)->through($job->middleware())->then(fn (SendNotification $j) => app()->call([$j, 'handle']));
}

beforeEach(function (): void {
    $this->flushRedis();
    config(['notifications.rate_limit.per_second' => 1_000_000]);
    $this->app->instance(ProviderRegistry::class, new ProviderRegistry([
        'sms' => fn () => acceptingProvider(),
        'email' => fn () => acceptingProvider(),
        'push' => fn () => acceptingProvider(),
    ]));
});

it('does not grow memory while a worker processes thousands of jobs', function (): void {
    $process = function (int $count): void {
        $ids = Notification::factory()->count($count)->status(NotificationStatus::Queued)->create()->pluck('id');
        foreach ($ids as $id) {
            runJob((new SendNotification($id, Channel::Sms))->withFakeQueueInteractions());
        }
    };

    $process(300); // warm-up: autoloading, opcode caches, connection buffers
    gc_collect_cycles();
    $before = memory_get_usage();

    $process(1500);
    gc_collect_cycles();
    $growth = memory_get_usage() - $before;

    expect(Notification::query()->where('status', NotificationStatus::Sent)->count())->toBe(1800)
        ->and($growth)->toBeLessThan(2 * 1024 * 1024); // < 2 MB for 1500 jobs, i.e. < 1.4 KB per job
});

it('does not grow memory across repeated batch requests', function (): void {
    config(['queue.default' => 'null']); // measure the API side only
    $create = app(CreateNotificationBatch::class);
    $items = fn (int $n): array => array_map(
        fn (int $i): NotificationData => new NotificationData(Channel::Sms, Priority::Normal, sprintf('+90555%07d', $i), "Message {$i}"),
        range(1, $n),
    );

    $create->handle($items(200));
    gc_collect_cycles();
    $before = memory_get_usage();

    foreach (range(1, 20) as $_) {
        $create->handle($items(200));
    }
    gc_collect_cycles();

    expect(memory_get_usage() - $before)->toBeLessThan(2 * 1024 * 1024)
        ->and(NotificationBatch::query()->count())->toBe(21);
});

it('stops exporting from the worker loop while the trace collector is down', function (): void {
    $provider = new class implements TracerProviderInterface
    {
        public int $flushes = 0;

        public function getTracer(string $name, ?string $version = null, ?string $schemaUrl = null, iterable $attributes = []): TracerInterface
        {
            return (new NoopTracerProvider)->getTracer($name);
        }

        public function forceFlush(): bool
        {
            $this->flushes++;
            usleep(300_000); // an unreachable collector: retries until they give up

            return true; // the SDK reports success even then
        }
    };
    $tracing = new Tracing($provider, TraceContextPropagator::getInstance());

    $startedAt = microtime(true);
    foreach (range(1, 50) as $_) {
        $tracing->flushIfDue(); // called after every job
    }

    expect($provider->flushes)->toBe(1)
        ->and(microtime(true) - $startedAt)->toBeLessThan(0.5);
});

it('prunes finished notifications and their attempts after the retention period, never work in progress', function (): void {
    config(['notifications.retention_days' => 30]);
    $old = now()->subDays(31);

    $prunable = collect([NotificationStatus::Sent, NotificationStatus::Failed, NotificationStatus::Cancelled])
        ->map(fn (NotificationStatus $s) => Notification::factory()->status($s)->create(['updated_at' => $old]));
    NotificationAttempt::query()->create([
        'notification_id' => $prunable->first()->id, 'attempt_number' => 1,
        'outcome' => AttemptOutcome::Success, 'http_status' => 202, 'duration_ms' => 10,
    ]);
    $inProgress = collect([NotificationStatus::Queued, NotificationStatus::Retrying, NotificationStatus::Scheduled, NotificationStatus::Processing])
        ->map(fn (NotificationStatus $s) => Notification::factory()->status($s)->create(['updated_at' => $old]));
    $recent = Notification::factory()->sent()->create();
    $emptyBatch = NotificationBatch::factory()->create(['created_at' => $old]);
    $liveBatch = NotificationBatch::factory()->create(['created_at' => $old]);
    $inProgress->first()->update(['batch_id' => $liveBatch->id, 'updated_at' => $old]);

    $this->artisan('model:prune')->assertSuccessful();

    expect(Notification::query()->whereKey($prunable->pluck('id'))->count())->toBe(0)
        ->and(NotificationAttempt::query()->count())->toBe(0)
        ->and(Notification::query()->whereKey($inProgress->pluck('id'))->count())->toBe(4)
        ->and(Notification::query()->whereKey($recent->id)->exists())->toBeTrue()
        ->and(NotificationBatch::query()->whereKey($emptyBatch->id)->exists())->toBeFalse()
        ->and(NotificationBatch::query()->whereKey($liveBatch->id)->exists())->toBeTrue();
});

it('schedules retention and keeps overlap locks short', function (): void {
    $events = collect(app(Schedule::class)->events())->keyBy(fn ($e) => trim(str($e->command)->after('artisan')->replace(["'", '"'], '')->toString()));

    expect($events->keys()->implode(' '))->toContain('model:prune')->toContain('queue:prune-failed');

    // A scheduler killed mid-run must not block these for Laravel's default 24 h.
    foreach (['notifications:dispatch-scheduled' => 2, 'notifications:recover' => 5] as $command => $minutes) {
        $event = $events->first(fn ($e, $key) => str_contains($key, $command));
        expect($event->withoutOverlapping)->toBeTrue()
            ->and($event->expiresAt)->toBe($minutes);
    }
});

it('recycles every worker pool and caps its memory', function (string $supervisor): void {
    $config = require base_path('config/horizon.php');
    $pool = $config['defaults'][$supervisor];

    expect($pool['memory'])->toBe(128)
        ->and($pool['maxJobs'])->toBeGreaterThan(0)
        ->and($pool['maxTime'])->toBeGreaterThan(0)
        ->and($pool['timeout'])->toBeLessThan((int) config('queue.connections.redis.retry_after'));
})->with(['notifications-sms', 'notifications-email', 'notifications-push', 'default']);
