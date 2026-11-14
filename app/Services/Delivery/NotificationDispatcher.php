<?php

declare(strict_types=1);

namespace App\Services\Delivery;

use App\Enums\Channel;
use App\Enums\NotificationStatus;
use App\Enums\Priority;
use App\Jobs\SendNotification;
use App\Models\Notification;
use App\Support\Tracing\Tracing;
use Illuminate\Contracts\Queue\Factory as Queue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use OpenTelemetry\API\Trace\SpanKind;
use Throwable;

/**
 * Hands notifications to the queues.
 *
 * The status is flipped to `queued` (conditionally) before the job is pushed.
 * If pushing fails, the rows go back to `pending`; if the process dies in
 * between, a row is left `queued` without a job. Either way the recovery
 * sweeper re-dispatches it. Pushing first would let a
 * worker see a notification that is not claimable yet and drop the job.
 */
final readonly class NotificationDispatcher
{
    private const int CHUNK_SIZE = 500;

    public function __construct(
        private Queue $queue,
        private Tracing $tracing,
    ) {}

    /**
     * @param  iterable<Notification|string>  $notifications  models or IDs
     * @param  list<NotificationStatus>  $from  statuses a notification may be dispatched from
     * @return int number of notifications dispatched
     */
    public function dispatch(iterable $notifications, array $from = [NotificationStatus::Pending]): int
    {
        $ids = [];
        foreach ($notifications as $notification) {
            $ids[] = $notification instanceof Notification ? $notification->id : $notification;
        }

        $dispatched = 0;

        foreach (array_chunk(array_values(array_unique($ids)), self::CHUNK_SIZE) as $chunk) {
            $dispatched += $this->dispatchChunk($chunk, $from);
        }

        return $dispatched;
    }

    public function dispatchOne(Notification $notification): bool
    {
        return $this->dispatch([$notification]) === 1;
    }

    /**
     * @param  list<string>  $ids
     * @param  list<NotificationStatus>  $from
     */
    private function dispatchChunk(array $ids, array $from): int
    {
        $now = now();

        // UPDATE ... RETURNING: one round trip, and only the rows this call
        // actually moved to `queued` get a job (no double dispatch when two
        // dispatchers race for the same notifications).
        $rows = DB::select(
            sprintf(
                'UPDATE notifications SET status = ?, queued_at = ?, next_attempt_at = NULL, updated_at = ?
                 WHERE id IN (%s) AND status IN (%s)
                 RETURNING id, channel, priority',
                implode(', ', array_fill(0, count($ids), '?')),
                implode(', ', array_fill(0, count($from), '?')),
            ),
            [
                NotificationStatus::Queued->value, $now, $now,
                ...$ids,
                ...array_map(fn (NotificationStatus $s): string => $s->value, $from),
            ],
        );

        $byQueue = [];
        foreach ($rows as $row) {
            /** @var object{id: string, channel: string, priority: string} $row */
            $channel = Channel::from($row->channel);
            $byQueue[$channel->queue(Priority::from($row->priority))][] = [$row->id, $channel];
        }

        $pushed = 0;

        foreach ($byQueue as $queueName => $items) {
            // Producer span per queue. Jobs are created inside it, so each one
            // carries this span's context to the worker that processes it.
            $pushed += $this->tracing->inSpan("send {$queueName}", function () use ($items, $queueName): int {
                $jobs = array_map(fn (array $item): SendNotification => new SendNotification($item[0], $item[1]), $items);

                try {
                    $this->queue->connection()->bulk($jobs, '', $queueName);

                    return count($jobs);
                } catch (Throwable $e) {
                    $this->revertToPending($jobs, $e);

                    return 0;
                }
            }, [
                'messaging.system' => 'redis',
                'messaging.operation.type' => 'send',
                'messaging.destination.name' => $queueName,
                'messaging.batch.message_count' => count($items),
            ], SpanKind::KIND_PRODUCER);
        }

        return $pushed;
    }

    /**
     * The queue is unavailable (e.g. Redis is down). The rows are already
     * persisted, so instead of failing the API request they go back to
     * `pending`: the recovery sweeper re-dispatches pending rows after a
     * minute, while a row left `queued` without a job would wait for the much
     * longer queued threshold.
     *
     * @param  list<SendNotification>  $jobs
     */
    private function revertToPending(array $jobs, Throwable $e): void
    {
        $ids = array_map(fn (SendNotification $job): string => $job->notificationId, $jobs);

        Notification::query()
            ->whereKey($ids)
            ->where('status', NotificationStatus::Queued)
            ->update(['status' => NotificationStatus::Pending, 'queued_at' => null, 'updated_at' => now()]);

        Log::error('notifications.dispatch_failed', [
            'count' => count($ids),
            'error' => $e->getMessage(),
        ]);
    }
}
