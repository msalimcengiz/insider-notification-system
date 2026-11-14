<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\NotificationBatchController;
use App\Http\Controllers\Api\V1\NotificationController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function (): void {
    Route::post('notifications', [NotificationController::class, 'store']);
    Route::post('notifications/batch', [NotificationBatchController::class, 'store']);
});
