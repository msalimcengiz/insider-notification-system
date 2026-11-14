<?php

declare(strict_types=1);

use App\Enums\Channel;
use App\Enums\Priority;

it('builds one queue per channel and priority', function (): void {
    expect(Channel::Sms->queue(Priority::High))->toBe('notifications-sms-high')
        ->and(Channel::Email->queue(Priority::Low))->toBe('notifications-email-low');
});

it('lists a channel\'s queues highest priority first', function (): void {
    expect(Channel::Push->queuesByPriority())->toBe([
        'notifications-push-high',
        'notifications-push-normal',
        'notifications-push-low',
    ]);
});

it('exposes raw values for validation rules', function (): void {
    expect(Channel::values())->toBe(['sms', 'email', 'push'])
        ->and(Priority::values())->toBe(['high', 'normal', 'low']);
});
