<?php

declare(strict_types=1);

use App\Http\Requests\NotificationItemShape;
use App\Http\Requests\NotificationPayload;
use App\Http\Requests\StoreNotificationBatchRequest;
use Illuminate\Support\Facades\Validator;

/*
 * Batch items are validated by NotificationItemShape (fast, hand-written),
 * single notifications by the declarative NotificationPayload::rules().
 * Both must flag exactly the same fields for the same input.
 */
dataset('item payloads', [
    'valid sms' => [['channel' => 'sms', 'recipient' => '+905551234567', 'content' => 'Hi']],
    'valid with everything' => [['channel' => 'email', 'recipient' => 'a@b.co', 'subject' => 'S', 'content' => 'Hi', 'priority' => 'low', 'scheduled_at' => now()->addDay()->toIso8601String(), 'idempotency_key' => 'k']],
    'valid template' => [['channel' => 'sms', 'recipient' => '+905551234567', 'template' => 'otp', 'variables' => ['code' => 1]]],
    'empty' => [[]],
    'nulls' => [['channel' => null, 'recipient' => null, 'content' => null]],
    'empty strings' => [['channel' => '', 'recipient' => '', 'content' => '']],
    'unknown channel and priority' => [['channel' => 'fax', 'priority' => 'urgent', 'recipient' => 'x', 'content' => 'Hi']],
    'wrong types' => [['channel' => 1, 'priority' => 2, 'recipient' => 3, 'subject' => 4, 'content' => 5, 'variables' => 'x']],
    'too long' => [['channel' => 'sms', 'recipient' => str_repeat('1', 321), 'template' => str_repeat('t', 101), 'idempotency_key' => str_repeat('k', 256)]],
    'content and template' => [['channel' => 'sms', 'recipient' => 'x', 'content' => 'Hi', 'template' => 'otp']],
    'past schedule' => [['channel' => 'sms', 'recipient' => 'x', 'content' => 'Hi', 'scheduled_at' => now()->subMinute()->toIso8601String()]],
    'far schedule' => [['channel' => 'sms', 'recipient' => 'x', 'content' => 'Hi', 'scheduled_at' => now()->addDays(40)->toIso8601String()]],
    'not a date' => [['channel' => 'sms', 'recipient' => 'x', 'content' => 'Hi', 'scheduled_at' => 'next week-ish']],
    'non-string idempotency key' => [['channel' => 'sms', 'recipient' => 'x', 'content' => 'Hi', 'idempotency_key' => ['a']]],
]);

it('flags the same fields as the declarative rules', function (array $item): void {
    $declarative = Validator::make($item, NotificationPayload::rules(withItemIdempotencyKey: true));
    $expected = array_keys($declarative->errors()->toArray());
    sort($expected);

    $actual = array_keys(NotificationItemShape::errors($item, withItemIdempotencyKey: true));
    sort($actual);

    expect($actual)->toBe($expected);
})->with('item payloads');

it('validates a 1000-item batch quickly', function (): void {
    $items = array_map(fn (int $i): array => ['channel' => 'sms', 'recipient' => sprintf('+90555%07d', $i), 'content' => "Message {$i}"], range(1, 1000));

    $startedAt = hrtime(true);
    foreach ($items as $item) {
        NotificationItemShape::errors($item, withItemIdempotencyKey: true);
    }
    $elapsedMs = (hrtime(true) - $startedAt) / 1_000_000;

    expect($elapsedMs)->toBeLessThan(100);
});

it('documents every item field but only runs the top-level rule through the validator', function (): void {
    $request = new StoreNotificationBatchRequest;
    $validationRules = (new ReflectionMethod($request, 'validationRules'))->invoke($request);

    expect(array_keys($validationRules))->toBe(['notifications'])
        ->and(array_keys($request->rules()))->toContain('notifications.*.channel', 'notifications.*.idempotency_key');
});
