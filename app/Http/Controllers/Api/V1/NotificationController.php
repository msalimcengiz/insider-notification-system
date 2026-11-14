<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Actions\CreateNotification;
use App\Http\Controllers\Controller;
use App\Http\Middleware\AssignCorrelationId;
use App\Http\Requests\StoreNotificationRequest;
use App\Http\Resources\NotificationResource;
use Illuminate\Http\JsonResponse;

final class NotificationController extends Controller
{
    /**
     * Create a notification.
     *
     * The notification is persisted and processed asynchronously; poll its
     * status with `GET /notifications/{id}`. Send an `Idempotency-Key` header
     * to make retries safe: repeating a request with the same key returns the
     * original notification (200) instead of creating a new one (202).
     *
     * @response 202 NotificationResource
     */
    public function store(StoreNotificationRequest $request, CreateNotification $create): JsonResponse
    {
        [$notification, $replayed] = $create->handle(
            $request->toData(),
            $request->idempotencyKey(),
            AssignCorrelationId::current(),
        );

        return NotificationResource::make($notification)
            ->response()
            ->setStatusCode($replayed ? 200 : 202)
            ->header('Idempotent-Replayed', $replayed ? 'true' : 'false');
    }
}
