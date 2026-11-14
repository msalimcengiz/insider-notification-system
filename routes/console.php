<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schedule;

// Runs inside the `scheduler` container (php artisan schedule:work).
// withoutOverlapping + onOneServer: safe with several scheduler replicas.

Schedule::command('notifications:dispatch-scheduled')
    ->everyTenSeconds()
    ->withoutOverlapping()
    ->onOneServer();

Schedule::command('notifications:recover')
    ->everyMinute()
    ->withoutOverlapping()
    ->onOneServer();

Schedule::command('horizon:snapshot')->everyFiveMinutes();
