<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\NotificationBatchController;
use App\Http\Controllers\Api\V1\NotificationController;
use Illuminate\Support\Facades\Route;

// IDs are UUIDs: anything else is a 404 instead of a database error.
Route::pattern('notification', '[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}');
Route::pattern('batch', '[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}');

Route::prefix('v1')->group(function (): void {
    Route::post('notifications/batch', [NotificationBatchController::class, 'store']);

    Route::get('notifications', [NotificationController::class, 'index']);
    Route::post('notifications', [NotificationController::class, 'store']);
    Route::get('notifications/{notification}', [NotificationController::class, 'show']);
    Route::post('notifications/{notification}/cancel', [NotificationController::class, 'cancel']);

    Route::get('batches/{batch}', [NotificationBatchController::class, 'show']);
    Route::post('batches/{batch}/cancel', [NotificationBatchController::class, 'cancel']);
});
