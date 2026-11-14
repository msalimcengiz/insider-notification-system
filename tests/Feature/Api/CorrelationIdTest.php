<?php

declare(strict_types=1);

use App\Models\Notification;

it('generates a correlation ID and stores it on the notification', function (): void {
    $response = $this->postJson('/api/v1/notifications', [
        'channel' => 'sms', 'recipient' => '+905551234567', 'content' => 'Hi',
    ]);

    $id = $response->headers->get('X-Correlation-ID');

    expect($id)->toBeUuid()
        ->and(Notification::query()->sole()->correlation_id)->toBe($id);
});

it('reuses a valid correlation ID sent by the client', function (): void {
    $this->postJson('/api/v1/notifications', [
        'channel' => 'sms', 'recipient' => '+905551234567', 'content' => 'Hi',
    ], ['X-Correlation-ID' => 'checkout-123'])
        ->assertHeader('X-Correlation-ID', 'checkout-123');

    expect(Notification::query()->sole()->correlation_id)->toBe('checkout-123');
});

it('replaces correlation IDs that are not safe to log', function (): void {
    $response = $this->postJson('/api/v1/notifications', [], ['X-Correlation-ID' => "bad id\nwith newline"]);

    expect($response->headers->get('X-Correlation-ID'))->toBeUuid();
});

it('returns the correlation ID on error responses too', function (): void {
    $this->postJson('/api/v1/notifications', [], ['X-Correlation-ID' => 'trace-1'])
        ->assertUnprocessable()
        ->assertHeader('X-Correlation-ID', 'trace-1');
});
