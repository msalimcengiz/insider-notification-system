<?php

declare(strict_types=1);

namespace App\Services\Delivery;

use App\Enums\Channel;
use App\Enums\NotificationStatus;
use App\Enums\Priority;
use App\Jobs\SendNotification;
use App\Models\Notification;
use Illuminate\Contracts\Queue\Factory as Queue;
use Illuminate\Support\Facades\DB;

/**
 * Hands notifications to the queues.
 *
 * The status is flipped to `queued` (conditionally) before the job is pushed.
 * If the process dies in between, the notification is left `queued` without
 * a job and the recovery sweeper re-dispatches it. Pushing first would let a
 * worker see a notification that is not claimable yet and drop the job.
 */
final readonly class NotificationDispatcher
{
    private const int CHUNK_SIZE = 500;

    public function __construct(private Queue $queue) {}

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
            $queueName = $channel->queue(Priority::from($row->priority));

            $byQueue[$queueName][] = new SendNotification($row->id, $channel);
        }

        foreach ($byQueue as $queueName => $jobs) {
            $this->queue->connection()->bulk($jobs, '', $queueName);
        }

        return count($rows);
    }
}
