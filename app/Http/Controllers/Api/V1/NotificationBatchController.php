<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Actions\CreateNotificationBatch;
use App\Http\Controllers\Controller;
use App\Http\Middleware\AssignCorrelationId;
use App\Http\Requests\StoreNotificationBatchRequest;
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
}
