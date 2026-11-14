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
use Dedoc\Scramble\Attributes\BodyParameter;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\HeaderParameter;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

#[Group('Notifications', weight: 1)]
final class NotificationController extends Controller
{
    /**
     * Create a notification.
     *
     * The notification is persisted and processed asynchronously; poll its
     * status with `GET /notifications/{id}`. Send an `Idempotency-Key` header
     * to make retries safe: repeating a request with the same key returns the
     * original notification (200) instead of creating a new one (202).
     */
    #[BodyParameter('channel', 'Delivery channel.', required: true, type: "'sms'|'email'|'push'", example: 'sms')]
    #[BodyParameter('recipient', 'E.164 phone number (sms), email address (email) or device token (push).', required: true, type: 'string', example: '+905551234567')]
    #[BodyParameter('content', 'Message text. Required unless `template` is given. SMS: max 10 segments (GSM-7 1530 / UCS-2 670 characters); push: 1000; email: 100000.', type: 'string', example: 'Your order has shipped.')]
    #[BodyParameter('priority', 'Defaults to normal.', type: "'high'|'normal'|'low'", example: 'high')]
    #[BodyParameter('subject', 'Email only.', type: 'string')]
    #[BodyParameter('template', 'Name of a template to render instead of `content`.', type: 'string', example: 'otp-sms')]
    #[BodyParameter('variables', 'Values for the template placeholders.', type: 'object', example: ['code' => '482913', 'minutes' => 5])]
    #[BodyParameter('scheduled_at', 'Deliver later (ISO 8601, future, max 30 days ahead).', type: 'string', format: 'date-time', example: '2026-12-01T09:00:00Z')]
    #[Response(202, 'Accepted for delivery (or 200 when replayed from an Idempotency-Key).', type: 'array{data: NotificationResource}')]
    #[HeaderParameter('Idempotency-Key', 'Makes retries safe: a repeated key returns the original notification (200) instead of creating a new one.', type: 'string', example: 'order-42-shipped')]
    #[HeaderParameter('X-Correlation-ID', 'Optional trace ID (letters, digits, ".", "_", "-"; max 64). Generated when missing and echoed on every response.', type: 'string', example: 'checkout-7f3a')]
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
