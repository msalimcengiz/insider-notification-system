<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\NotificationStatus;
use App\Events\NotificationStatusChanged;
use App\Http\Middleware\AssignCorrelationId;
use App\Models\Notification;
use App\Models\NotificationAttempt;
use App\Services\CircuitBreaker\CircuitBreaker;
use App\Services\Delivery\DeliveryResult;
use App\Services\Delivery\ProviderRegistry;
use App\Services\Delivery\RetryPolicy;
use App\Services\Metrics\MetricsRecorder;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use OpenTelemetry\API\Trace\Span;

/**
 * One delivery attempt: claim → call provider → record the outcome.
 */
final readonly class DeliverNotification
{
    public function __construct(
        private ProviderRegistry $providers,
        private RetryPolicy $retryPolicy,
        private CircuitBreaker $circuitBreaker,
        private MetricsRecorder $metrics,
    ) {}

    /**
     * @return int|null seconds after which the job must run again, or null when done
     */
    public function handle(string $notificationId): ?int
    {
        $notification = $this->claim($notificationId);

        if ($notification === null) {
            return null;
        }

        // Logs written from here on carry the notification's own correlation
        // ID (Context is restored per job), whatever dispatched the job.
        Context::add('notification_id', $notification->id);
        if ($notification->correlation_id !== null) {
            Context::add(AssignCorrelationId::CONTEXT_KEY, $notification->correlation_id);
        }

        $result = $this->providers->for($notification->channel)->send($notification);

        // Only transient failures say something about the provider's health.
        match (true) {
            $result->succeeded() => $this->circuitBreaker->recordSuccess($notification->channel),
            $result->isRetryable() => $this->circuitBreaker->recordFailure($notification->channel),
            default => null,
        };

        return $this->record($notification, $result);
    }

    /**
     * Atomically moves the notification to `processing`. Fails (returns null)
     * when it was cancelled, already sent, or is being handled by another
     * worker, which also makes duplicate jobs harmless.
     */
    private function claim(string $notificationId): ?Notification
    {
        $now = now();

        $claimed = Notification::query()
            ->whereKey($notificationId)
            ->inStatus([NotificationStatus::Queued, NotificationStatus::Retrying])
            ->update([
                'status' => NotificationStatus::Processing,
                'processing_started_at' => $now,
                'next_attempt_at' => null,
                'updated_at' => $now,
            ]);

        if ($claimed === 0) {
            Log::info('notification.skipped', [
                'notification_id' => $notificationId,
                'status' => Notification::query()->whereKey($notificationId)->value('status'),
            ]);

            return null;
        }

        return Notification::query()->findOrFail($notificationId);
    }

    private function record(Notification $notification, DeliveryResult $result): ?int
    {
        $attempt = $notification->attempts + 1;
        $retryIn = null;
        $now = now();

        $changes = [
            'attempts' => $attempt,
            'last_error' => $result->error,
        ];

        if ($result->succeeded()) {
            $changes += [
                'status' => NotificationStatus::Sent,
                'provider_message_id' => $result->providerMessageId,
                'sent_at' => $now,
            ];
        } elseif ($result->isRetryable() && $this->retryPolicy->shouldRetry($attemptsInBudget = $attempt - $notification->retry_budget_start)) {
            $retryIn = $this->retryPolicy->delayFor($attemptsInBudget, $result->retryAfter);
            $changes += [
                'status' => NotificationStatus::Retrying,
                'next_attempt_at' => $now->addSeconds($retryIn),
            ];
        } else {
            $changes += [
                'status' => NotificationStatus::Failed,
                'failed_at' => $now,
            ];
        }

        DB::transaction(function () use ($notification, $result, $attempt, $changes): void {
            NotificationAttempt::query()->create([
                'notification_id' => $notification->id,
                'attempt_number' => $attempt,
                'outcome' => $result->outcome,
                'http_status' => $result->httpStatus,
                'error_message' => $result->error,
                'response_body' => $result->responseBody,
                'duration_ms' => $result->durationMs,
            ]);

            $notification->update($changes);
        });

        event(NotificationStatusChanged::from($notification));

        $this->metrics->recordAttempt(
            $notification->channel,
            $result->outcome,
            $result->durationMs,
            $notification->status,
            $result->succeeded() ? (int) $notification->created_at->diffInMilliseconds($now, true) : null,
        );

        Span::getCurrent()->setAttributes(array_filter([
            'notification.attempt' => $attempt,
            'notification.status' => $notification->status->value,
            'notification.retry_in_seconds' => $retryIn,
        ], fn (mixed $value): bool => $value !== null));

        Log::log($result->succeeded() ? 'info' : 'warning', 'notification.attempt', [
            'notification_id' => $notification->id,
            'channel' => $notification->channel->value,
            'attempt' => $attempt,
            'outcome' => $result->outcome->value,
            'http_status' => $result->httpStatus,
            'duration_ms' => $result->durationMs,
            'status' => $notification->status->value,
            'retry_in' => $retryIn,
            'error' => $result->error,
        ]);

        return $retryIn;
    }
}
