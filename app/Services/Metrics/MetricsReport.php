<?php

declare(strict_types=1);

namespace App\Services\Metrics;

use App\Enums\Channel;
use App\Enums\NotificationStatus;
use App\Enums\Priority;
use App\Models\Notification;
use App\Services\CircuitBreaker\CircuitBreaker;
use Illuminate\Contracts\Queue\Factory as Queue;
use Illuminate\Queue\RedisQueue;

/**
 * Builds the GET /metrics payload.
 */
final readonly class MetricsReport
{
    public function __construct(
        private MetricsRecorder $recorder,
        private CircuitBreaker $circuitBreaker,
        private Queue $queue,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function build(int $windowMinutes): array
    {
        $channels = [];
        $overall = ['sent' => 0, 'failed' => 0, 'attempts' => 0, 'failed_attempts' => 0];

        foreach (Channel::cases() as $channel) {
            $totals = $this->recorder->totals($channel, $windowMinutes);
            $channels[$channel->value] = $this->channelReport($channel, $totals, $windowMinutes);

            $overall['sent'] += $totals['sent'] ?? 0;
            $overall['failed'] += $totals['failed'] ?? 0;
            $overall['attempts'] += $totals['provider_ms:count'] ?? 0;
            $overall['failed_attempts'] += ($totals['attempts:retryable_failure'] ?? 0) + ($totals['attempts:permanent_failure'] ?? 0);
        }

        return [
            'generated_at' => now()->toIso8601ZuluString(),
            'window_minutes' => $windowMinutes,
            'overall' => [
                'sent' => $overall['sent'],
                'failed' => $overall['failed'],
                'success_rate' => $this->ratio($overall['sent'], $overall['sent'] + $overall['failed']),
                'attempt_failure_rate' => $this->ratio($overall['failed_attempts'], $overall['attempts']),
                'throughput_per_second' => round($overall['sent'] / ($windowMinutes * 60), 2),
            ],
            'channels' => $channels,
            'notifications_in_flight' => $this->inFlight(),
        ];
    }

    /**
     * @param  array<string, int>  $totals
     * @return array<string, mixed>
     */
    private function channelReport(Channel $channel, array $totals, int $windowMinutes): array
    {
        $sent = $totals['sent'] ?? 0;
        $failed = $totals['failed'] ?? 0;
        $attempts = $totals['provider_ms:count'] ?? 0;
        $retryable = $totals['attempts:retryable_failure'] ?? 0;
        $permanent = $totals['attempts:permanent_failure'] ?? 0;

        return [
            'sent' => $sent,
            'failed' => $failed,
            'retries_scheduled' => $totals['retrying'] ?? 0,
            'success_rate' => $this->ratio($sent, $sent + $failed),
            'attempts' => [
                'total' => $attempts,
                'success' => $totals['attempts:success'] ?? 0,
                'retryable_failure' => $retryable,
                'permanent_failure' => $permanent,
                'failure_rate' => $this->ratio($retryable + $permanent, $attempts),
            ],
            'throughput_per_second' => round($sent / ($windowMinutes * 60), 2),
            'latency_ms' => [
                'provider' => $this->latency($this->recorder->providerLatency, $totals, 'provider_ms'),
                'end_to_end' => $this->latency($this->recorder->endToEndLatency, $totals, 'e2e_ms'),
            ],
            'queues' => $this->queues($channel),
            'circuit' => $this->circuitBreaker->state($channel)->value,
        ];
    }

    /**
     * @param  array<string, int>  $totals
     * @return array<string, float|null>
     */
    private function latency(Histogram $histogram, array $totals, string $prefix): array
    {
        $counts = MetricsRecorder::histogramCounts($totals, $prefix);
        $count = $totals["{$prefix}:count"] ?? 0;

        return [
            'avg' => $count > 0 ? round(($totals["{$prefix}:sum"] ?? 0) / $count, 1) : null,
            'p50' => $histogram->quantile($counts, 0.50),
            'p95' => $histogram->quantile($counts, 0.95),
            'p99' => $histogram->quantile($counts, 0.99),
        ];
    }

    /**
     * Queue depth straight from Redis, per priority.
     *
     * @return array<string, array<string, int|null>>
     */
    private function queues(Channel $channel): array
    {
        $connection = $this->queue->connection('redis');
        $result = [];

        foreach (Priority::ordered() as $priority) {
            $name = $channel->queue($priority);

            if (! $connection instanceof RedisQueue) {
                $result[$priority->value] = ['depth' => $connection->size($name)];

                continue;
            }

            $oldest = $connection->creationTimeOfOldestPendingJob($name);

            $result[$priority->value] = [
                'pending' => $connection->pendingSize($name),
                'delayed' => $connection->delayedSize($name),
                'reserved' => $connection->reservedSize($name),
                'oldest_pending_age_seconds' => $oldest !== null ? max(0, now()->getTimestamp() - (int) $oldest) : null,
            ];
        }

        return $result;
    }

    /**
     * Non-final notifications by status (served by the partial indexes).
     *
     * @return array<string, int>
     */
    private function inFlight(): array
    {
        $statuses = [
            NotificationStatus::Scheduled,
            NotificationStatus::Pending,
            NotificationStatus::Queued,
            NotificationStatus::Processing,
            NotificationStatus::Retrying,
        ];

        $counts = Notification::query()
            ->toBase()
            ->whereIn('status', array_map(fn (NotificationStatus $s): string => $s->value, $statuses))
            ->selectRaw('status, count(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        $result = [];
        foreach ($statuses as $status) {
            $result[$status->value] = (int) ($counts[$status->value] ?? 0);
        }

        return $result;
    }

    private function ratio(int $part, int $total): ?float
    {
        return $total > 0 ? round($part / $total, 4) : null;
    }
}
