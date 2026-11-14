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
use Dedoc\Scramble\Attributes\BodyParameter;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\HeaderParameter;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;

#[Group('Batches', weight: 2)]
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
    #[BodyParameter('notifications', 'Up to 1000 notifications, each with the fields of `POST /notifications` plus an optional `idempotency_key`.', required: true, type: 'array', example: [
        ['channel' => 'sms', 'recipient' => '+905551234567', 'content' => 'Flash sale: 40% off for one hour!', 'priority' => 'high', 'idempotency_key' => 'sale-42-user-1'],
        ['channel' => 'email', 'recipient' => 'ada@example.com', 'template' => 'order-shipped-email', 'variables' => ['name' => 'Ada', 'order_id' => '42', 'eta' => 'Friday']],
        ['channel' => 'push', 'recipient' => 'fcm-token-0123456789abcdef0123456789abcdef', 'content' => 'Your order is on its way', 'priority' => 'low'],
    ])]
    #[Response(202, 'Accepted for delivery (or 200 when replayed from an Idempotency-Key).', type: 'array{data: array{batch_id: string, accepted: int, duplicates: int, notifications: list<array{index: int, id: string, duplicate: bool}>}}')]
    #[HeaderParameter('Idempotency-Key', 'Makes retries safe: a repeated key returns the original batch (200).', type: 'string', example: 'campaign-2026-11-flash-sale')]
    #[HeaderParameter('X-Correlation-ID', 'Optional trace ID, echoed on every response.', type: 'string')]
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
