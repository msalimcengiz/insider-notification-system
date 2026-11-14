<?php

declare(strict_types=1);

use App\Actions\DeliverNotification;
use App\Enums\NotificationStatus;
use App\Models\Notification;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

it('logs every API request with its correlation ID', function (): void {
    Queue::fake();
    $logged = [];
    Event::listen(MessageLogged::class, function (MessageLogged $e) use (&$logged): void {
        $logged[] = ['message' => $e->message, 'context' => $e->context, 'correlation_id' => Context::get('correlation_id')];
    });

    $this->postJson('/api/v1/notifications', [
        'channel' => 'sms', 'recipient' => '+905551234567', 'content' => 'Hi',
    ], ['X-Correlation-ID' => 'trace-42'])->assertAccepted();

    $request = collect($logged)->firstWhere('message', 'http.request');

    expect($request)->not->toBeNull()
        ->and($request['context']['method'])->toBe('POST')
        ->and($request['context']['path'])->toBe('/api/v1/notifications')
        ->and($request['context']['status'])->toBe(202)
        ->and($request['context']['duration_ms'])->toBeFloat()
        ->and($request['correlation_id'])->toBe('trace-42');
});

it('logs delivery attempts with the notification\'s own correlation ID', function (): void {
    $this->flushRedis();
    Http::fake(['*' => Http::response(['messageId' => 'm'], 202)]);
    $notification = Notification::factory()->status(NotificationStatus::Queued)->create(['correlation_id' => 'from-api']);
    Context::add('correlation_id', 'from-scheduler');

    $logged = [];
    Event::listen(MessageLogged::class, function (MessageLogged $e) use (&$logged): void {
        $logged[] = ['message' => $e->message, 'context' => $e->context, 'extra' => Context::all()];
    });

    app(DeliverNotification::class)->handle($notification->id);

    $attempt = collect($logged)->firstWhere('message', 'notification.attempt');

    expect($attempt['context']['outcome'])->toBe('success')
        ->and($attempt['extra']['correlation_id'])->toBe('from-api')
        ->and($attempt['extra']['notification_id'])->toBe($notification->id);
});

it('writes JSON lines to stderr when configured', function (): void {
    expect(config('logging.channels.stderr.formatter'))->toBe(env('LOG_STDERR_FORMATTER'));
    expect(file_get_contents(base_path('.env.example')))->toContain('LOG_STDERR_FORMATTER=Monolog\Formatter\JsonFormatter');
});
