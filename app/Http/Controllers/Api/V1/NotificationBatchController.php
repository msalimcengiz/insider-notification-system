<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Actions\CancelNotificationBatch;
use App\Actions\CreateNotificationBatch;
use App\Actions\RetryFailedNotifications;
use App\Http\Controllers\Controller;
use App\Http\Middleware\AssignCorrelationId;
use App\Http\Requests\StoreNotificationBatchRequest;
use App\Http\Resources\NotificationBatchResource;
use App\Models\NotificationBatch;
use Illuminate\Http\JsonResponse;

final class NotificationBatchController extends Controller
{
    /**
     * Create a batch of notifications.
     *
     * Accepts up to 1000 notifications. The whole request is validated first;
     * if any item is invalid nothing is created and the errors point at the
     * offending items (`notifications.{index}.{field}`).
     *
     * Items may carry their own `idempotency_key`. Items whose key was already
     * used are not created again and are reported with `duplicate: true` and
     * the ID of the existing notification.
     */
    public function store(StoreNotificationBatchRequest $request, CreateNotificationBatch $create): JsonResponse
    {
        $result = $create->handle(
            $request->toData(),
            $request->idempotencyKey(),
            AssignCorrelationId::current(),
        );

        return response()
            ->json([
                'data' => [
                    'batch_id' => $result->batch->id,
                    'accepted' => $result->acceptedCount(),
                    'duplicates' => $result->duplicateCount(),
                    'notifications' => $result->items,
                ],
            ], $result->replayed ? 200 : 202)
            ->header('Idempotent-Replayed', $result->replayed ? 'true' : 'false');
    }

    /**
     * Get a batch.
     *
     * Aggregated progress of the batch: how many notifications are in each
     * status. List the notifications themselves with
     * `GET /notifications?batch_id={id}`.
     */
    public function show(NotificationBatch $batch): NotificationBatchResource
    {
        return NotificationBatchResource::make($batch);
    }

    /**
     * Cancel a batch.
     *
     * Cancels every notification of the batch that is still cancellable.
     * Notifications already being processed or finished are not affected.
     */
    public function cancel(NotificationBatch $batch, CancelNotificationBatch $cancel): JsonResponse
    {
        $cancelled = $cancel->handle($batch);

        return NotificationBatchResource::make($batch)
            ->additional(['meta' => ['cancelled' => $cancelled]])
            ->response();
    }

    /**
     * Retry the failed notifications of a batch.
     *
     * Re-queues every `failed` notification of the batch with a fresh retry
     * budget and reports how many were re-queued.
     */
    public function retry(NotificationBatch $batch, RetryFailedNotifications $retry): JsonResponse
    {
        $retried = $retry->batch($batch);

        return NotificationBatchResource::make($batch)
            ->additional(['meta' => ['retried' => $retried]])
            ->response();
    }
}
