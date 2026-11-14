<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\HealthController;
use App\Http\Controllers\Api\V1\MetricsController;
use App\Http\Controllers\Api\V1\NotificationBatchController;
use App\Http\Controllers\Api\V1\NotificationController;
use App\Http\Controllers\Api\V1\TemplateController;
use Illuminate\Support\Facades\Route;

// IDs are UUIDs: anything else is a 404 instead of a database error.
Route::pattern('notification', '[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}');
Route::pattern('template', '[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}');
Route::pattern('batch', '[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}');

Route::prefix('v1')->group(function (): void {
    Route::post('notifications/batch', [NotificationBatchController::class, 'store']);

    Route::get('notifications', [NotificationController::class, 'index']);
    Route::post('notifications', [NotificationController::class, 'store']);
    Route::get('notifications/{notification}', [NotificationController::class, 'show']);
    Route::post('notifications/{notification}/cancel', [NotificationController::class, 'cancel']);
    Route::post('notifications/{notification}/retry', [NotificationController::class, 'retry']);

    Route::apiResource('templates', TemplateController::class);
    Route::post('templates/{template}/preview', [TemplateController::class, 'preview']);

    Route::get('metrics', MetricsController::class);
    Route::get('health', HealthController::class);

    Route::get('batches/{batch}', [NotificationBatchController::class, 'show']);
    Route::post('batches/{batch}/cancel', [NotificationBatchController::class, 'cancel']);
    Route::post('batches/{batch}/retry', [NotificationBatchController::class, 'retry']);
});
