<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Actions\CancelNotification;
use App\Actions\CreateNotification;
use App\Actions\RetryFailedNotifications;
use App\Http\Controllers\Controller;
use App\Http\Middleware\AssignCorrelationId;
use App\Http\Requests\ListNotificationsRequest;
use App\Http\Requests\StoreNotificationRequest;
use App\Http\Resources\NotificationResource;
use App\Models\Notification;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

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

    /**
     * List notifications.
     *
     * Newest first, cursor paginated (follow `links.next`). Multi-value
     * filters take a comma separated list, e.g. `?status=queued,retrying`.
     * `from` / `to` filter on the creation time.
     */
    public function index(ListNotificationsRequest $request): AnonymousResourceCollection
    {
        $query = Notification::query()
            ->when($request->values('status'), fn (Builder $q, array $v) => $q->whereIn('status', $v))
            ->when($request->values('channel'), fn (Builder $q, array $v) => $q->whereIn('channel', $v))
            ->when($request->values('priority'), fn (Builder $q, array $v) => $q->whereIn('priority', $v))
            ->when($request->filter('batch_id'), fn (Builder $q, string $v) => $q->where('batch_id', $v))
            ->when($request->filter('recipient'), fn (Builder $q, string $v) => $q->where('recipient', $v))
            ->when($request->filter('from'), fn (Builder $q, string $v) => $q->where('created_at', '>=', $v))
            ->when($request->filter('to'), fn (Builder $q, string $v) => $q->where('created_at', '<=', $v))
            ->orderByDesc('created_at')
            ->orderByDesc('id');

        return NotificationResource::collection(
            $query->cursorPaginate($request->perPage())->withQueryString(),
        );
    }

    /**
     * Get a notification.
     *
     * Includes the delivery attempt history.
     */
    public function show(Notification $notification): NotificationResource
    {
        return NotificationResource::make($notification->load('deliveryAttempts'));
    }

    /**
     * Cancel a notification.
     *
     * Only notifications that have not reached a worker yet can be cancelled
     * (scheduled, pending, queued, or waiting for a retry). Cancelling an
     * already cancelled notification succeeds. Otherwise responds with 409.
     */
    public function cancel(Notification $notification, CancelNotification $cancel): NotificationResource
    {
        return NotificationResource::make($cancel->handle($notification));
    }

    /**
     * Retry a failed notification.
     *
     * Re-queues a notification that ended in `failed` with a fresh retry
     * budget; its attempt history is kept. Responds with 409 for any other
     * status.
     */
    public function retry(Notification $notification, RetryFailedNotifications $retry): NotificationResource
    {
        return NotificationResource::make($retry->one($notification));
    }
}
