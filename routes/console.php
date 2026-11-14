<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schedule;

// Runs inside the `scheduler` container (php artisan schedule:work).
// withoutOverlapping + onOneServer: safe with several scheduler replicas.
// The overlap locks expire after a few minutes (Laravel's default is 24 h):
// if the scheduler is killed mid-run, the next runs must not be blocked for
// a day, which would hold every scheduled notification back.

Schedule::command('notifications:dispatch-scheduled')
    ->everyTenSeconds()
    ->withoutOverlapping(expiresAt: 2)
    ->onOneServer();

Schedule::command('notifications:recover')
    ->everyMinute()
    ->withoutOverlapping(expiresAt: 5)
    ->onOneServer();

Schedule::command('horizon:snapshot')->everyFiveMinutes();

// Retention, so tables do not grow for as long as the server runs.
Schedule::command('model:prune')->dailyAt('03:00')->withoutOverlapping(expiresAt: 60)->onOneServer();
Schedule::command('queue:prune-failed', ['--hours' => 24 * 14])->dailyAt('03:30')->onOneServer();
