<?php

declare(strict_types=1);

it('runs one fixed-size supervisor per channel draining queues by priority', function (string $channel): void {
    $config = require __DIR__.'/../../config/horizon.php';
    $supervisor = $config['defaults']["notifications-{$channel}"];

    expect($supervisor['queue'])->toBe([
        "notifications-{$channel}-high",
        "notifications-{$channel}-normal",
        "notifications-{$channel}-low",
    ])
        ->and($supervisor['balance'])->toBeFalse()
        ->and($supervisor['minProcesses'])->toBe($supervisor['maxProcesses'])
        ->and($supervisor['tries'])->toBe(0);
})->with(['sms', 'email', 'push']);
