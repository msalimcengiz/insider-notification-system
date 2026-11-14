<?php

declare(strict_types=1);

use App\Enums\NotificationStatus;
use App\Jobs\SendNotification;
use App\Models\Notification;
use App\Models\Template;
use Illuminate\Support\Facades\Queue;

const NOTIFICATIONS_URL = '/api/v1/notifications';

function smsPayload(array $overrides = []): array
{
    return [
        'channel' => 'sms',
        'recipient' => '+905551234567',
        'content' => 'Your order has shipped.',
        ...$overrides,
    ];
}

it('creates a notification, queues it on its channel/priority queue and returns 202', function (): void {
    $response = $this->postJson(NOTIFICATIONS_URL, smsPayload(['priority' => 'high']));

    $response->assertAccepted()
        ->assertHeader('Idempotent-Replayed', 'false')
        ->assertJsonPath('data.status', 'queued')
        ->assertJsonPath('data.priority', 'high')
        ->assertJsonPath('data.channel', 'sms');

    $notification = Notification::query()->sole();
    expect($notification->status)->toBe(NotificationStatus::Queued)
        ->and($notification->queued_at)->not->toBeNull()
        ->and($response->json('data.id'))->toBe($notification->id);

    Queue::assertPushedOn('notifications-sms-high', SendNotification::class,
        fn (SendNotification $job): bool => $job->notificationId === $notification->id);
});

it('does not queue scheduled notifications yet', function (): void {
    $this->postJson(NOTIFICATIONS_URL, smsPayload(['scheduled_at' => now()->addHour()->toIso8601String()]))
        ->assertAccepted();

    Queue::assertNothingPushed();
});

it('does not queue a replayed request again', function (): void {
    $this->postJson(NOTIFICATIONS_URL, smsPayload(), ['Idempotency-Key' => 'k'])->assertAccepted();
    $this->postJson(NOTIFICATIONS_URL, smsPayload(), ['Idempotency-Key' => 'k'])->assertOk();

    Queue::assertPushed(SendNotification::class, 1);
});

it('defaults to normal priority', function (): void {
    $this->postJson(NOTIFICATIONS_URL, smsPayload())->assertJsonPath('data.priority', 'normal');
});

it('accepts each channel', function (array $payload): void {
    $this->postJson(NOTIFICATIONS_URL, $payload)->assertAccepted();
})->with([
    'sms' => [['channel' => 'sms', 'recipient' => '+905551234567', 'content' => 'Hi']],
    'email' => [['channel' => 'email', 'recipient' => 'ada@example.com', 'subject' => 'Hello', 'content' => 'Hi']],
    'email without subject' => [['channel' => 'email', 'recipient' => 'ada@example.com', 'content' => 'Hi']],
    'push' => [['channel' => 'push', 'recipient' => str_repeat('a1', 32), 'content' => 'Hi']],
]);

it('validates required fields and enums', function (): void {
    $this->postJson(NOTIFICATIONS_URL, ['channel' => 'fax', 'priority' => 'urgent'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['channel', 'priority', 'recipient', 'content']);
});

it('validates recipients per channel', function (string $channel, string $recipient): void {
    $this->postJson(NOTIFICATIONS_URL, ['channel' => $channel, 'recipient' => $recipient, 'content' => 'Hi'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['recipient']);
})->with([
    'sms without +' => ['sms', '905551234567'],
    'sms too short' => ['sms', '+90555'],
    'email' => ['email', 'not-an-email'],
    'push token too short' => ['push', 'abc'],
]);

it('rejects SMS content longer than the segment limit', function (): void {
    config(['notifications.content.sms.max_segments' => 2]);

    $this->postJson(NOTIFICATIONS_URL, smsPayload(['content' => str_repeat('a', 307)]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['content' => 'segments']);

    $this->postJson(NOTIFICATIONS_URL, smsPayload(['content' => str_repeat('a', 306)]))
        ->assertAccepted();
});

it('applies the stricter UCS-2 limit to Turkish SMS content', function (): void {
    config(['notifications.content.sms.max_segments' => 1]);

    $this->postJson(NOTIFICATIONS_URL, smsPayload(['content' => str_repeat('ş', 71)]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['content']);
});

it('rejects push content above the character limit', function (): void {
    $this->postJson(NOTIFICATIONS_URL, [
        'channel' => 'push',
        'recipient' => str_repeat('a1', 32),
        'content' => str_repeat('a', 1001),
    ])->assertUnprocessable()->assertJsonValidationErrors(['content']);
});

it('only allows a subject for email', function (): void {
    $this->postJson(NOTIFICATIONS_URL, smsPayload(['subject' => 'Hi']))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['subject']);
});

it('rejects blank content', function (): void {
    $this->postJson(NOTIFICATIONS_URL, smsPayload(['content' => '   ']))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['content']);
});

describe('templates', function (): void {
    beforeEach(function (): void {
        Template::factory()->create([
            'name' => 'shipped',
            'channel' => 'email',
            'subject' => 'Order {{ order_id }} shipped',
            'body' => 'Hi {{ name }}, order {{ order_id }} is on its way.',
        ]);
    });

    it('renders the template body and subject', function (): void {
        $this->postJson(NOTIFICATIONS_URL, [
            'channel' => 'email',
            'recipient' => 'ada@example.com',
            'template' => 'shipped',
            'variables' => ['name' => 'Ada', 'order_id' => 42],
        ])->assertAccepted()
            ->assertJsonPath('data.content', 'Hi Ada, order 42 is on its way.')
            ->assertJsonPath('data.subject', 'Order 42 shipped');

        expect(Notification::query()->sole()->template_variables)->toBe(['name' => 'Ada', 'order_id' => 42]);
    });

    it('reports missing variables', function (): void {
        $this->postJson(NOTIFICATIONS_URL, [
            'channel' => 'email',
            'recipient' => 'ada@example.com',
            'template' => 'shipped',
            'variables' => ['name' => 'Ada'],
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['variables' => 'order_id']);
    });

    it('rejects unknown templates and templates of another channel', function (string $channel, string $recipient, string $template): void {
        $this->postJson(NOTIFICATIONS_URL, [
            'channel' => $channel,
            'recipient' => $recipient,
            'template' => $template,
        ])->assertUnprocessable()->assertJsonValidationErrors(['template']);
    })->with([
        'unknown' => ['email', 'ada@example.com', 'missing'],
        'wrong channel' => ['sms', '+905551234567', 'shipped'],
    ]);

    it('does not accept content and a template together', function (): void {
        $this->postJson(NOTIFICATIONS_URL, [
            'channel' => 'email',
            'recipient' => 'ada@example.com',
            'template' => 'shipped',
            'content' => 'Hi',
        ])->assertUnprocessable()->assertJsonValidationErrors(['template']);
    });

    it('requires variables to be scalar values', function (): void {
        $this->postJson(NOTIFICATIONS_URL, [
            'channel' => 'email',
            'recipient' => 'ada@example.com',
            'template' => 'shipped',
            'variables' => ['name' => ['nested' => true], 'order_id' => 1],
        ])->assertUnprocessable()->assertJsonValidationErrors(['variables']);
    });
});

describe('scheduling', function (): void {
    it('stores future notifications as scheduled', function (): void {
        $at = now()->addHour()->startOfSecond();

        $this->postJson(NOTIFICATIONS_URL, smsPayload(['scheduled_at' => $at->toIso8601String()]))
            ->assertAccepted()
            ->assertJsonPath('data.status', 'scheduled')
            ->assertJsonPath('data.scheduled_at', $at->utc()->toIso8601ZuluString());
    });

    it('normalises scheduled_at to UTC', function (): void {
        $local = now()->addDay()->startOfSecond()->setTimezone('Europe/Istanbul');

        $this->postJson(NOTIFICATIONS_URL, smsPayload(['scheduled_at' => $local->toIso8601String()]))
            ->assertAccepted()
            ->assertJsonPath('data.scheduled_at', $local->utc()->toIso8601ZuluString());

        expect(Notification::query()->sole()->scheduled_at?->equalTo($local))->toBeTrue();
    });

    it('rejects past and too distant dates', function (string $at): void {
        $this->postJson(NOTIFICATIONS_URL, smsPayload(['scheduled_at' => $at]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['scheduled_at']);
    })->with([
        'past' => fn () => now()->subMinute()->toIso8601String(),
        'beyond limit' => fn () => now()->addDays(31)->toIso8601String(),
        'not a date' => ['tomorrow-ish'],
    ]);
});

describe('idempotency', function (): void {
    it('returns the original notification when a key is repeated', function (): void {
        $first = $this->postJson(NOTIFICATIONS_URL, smsPayload(), ['Idempotency-Key' => 'order-42'])->assertAccepted();

        $this->postJson(NOTIFICATIONS_URL, smsPayload(), ['Idempotency-Key' => 'order-42'])
            ->assertOk()
            ->assertHeader('Idempotent-Replayed', 'true')
            ->assertJsonPath('data.id', $first->json('data.id'));

        expect(Notification::query()->count())->toBe(1);
    });

    it('rejects a key reused with a different payload', function (): void {
        $this->postJson(NOTIFICATIONS_URL, smsPayload(), ['Idempotency-Key' => 'order-42'])->assertAccepted();

        $this->postJson(NOTIFICATIONS_URL, smsPayload(['content' => 'Something else']), ['Idempotency-Key' => 'order-42'])
            ->assertConflict()
            ->assertJsonPath('message', 'The idempotency key "order-42" was already used for a different request.');
    });

    it('creates separate notifications without a key', function (): void {
        $this->postJson(NOTIFICATIONS_URL, smsPayload())->assertAccepted();
        $this->postJson(NOTIFICATIONS_URL, smsPayload())->assertAccepted();

        expect(Notification::query()->count())->toBe(2);
    });

    it('rejects overly long keys', function (): void {
        $this->postJson(NOTIFICATIONS_URL, smsPayload(), ['Idempotency-Key' => str_repeat('k', 256)])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['Idempotency-Key']);
    });
});
