<?php

declare(strict_types=1);

namespace App\Actions;

use App\DTOs\NotificationData;
use App\Enums\NotificationStatus;
use App\Models\Notification;
use App\Models\NotificationBatch;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Persists up to `notifications.batch.max_size` notifications with a handful
 * of queries instead of one INSERT per item.
 *
 * Idempotency works on two levels:
 *  - Batch: the Idempotency-Key header. Repeating it returns the original batch.
 *  - Item: each item's optional idempotency_key. Items whose key already exists
 *    (from any earlier request) are reported as duplicates and not created
 *    again, so a client can safely resend a partially processed batch.
 */
final readonly class CreateNotificationBatch
{
    private const int INSERT_CHUNK_SIZE = 500;

    /**
     * @param  list<NotificationData>  $items
     */
    public function handle(array $items, ?string $idempotencyKey = null, ?string $correlationId = null): BatchCreationResult
    {
        if ($idempotencyKey !== null && ($existing = $this->findBatch($idempotencyKey)) !== null) {
            return $this->replay($existing);
        }

        try {
            return DB::transaction(fn (): BatchCreationResult => $this->create($items, $idempotencyKey, $correlationId));
        } catch (UniqueConstraintViolationException $e) {
            // Lost a race against a concurrent request with the same batch key.
            $existing = $idempotencyKey !== null ? $this->findBatch($idempotencyKey) : null;

            return $existing !== null ? $this->replay($existing) : throw $e;
        }
    }

    /**
     * @param  list<NotificationData>  $items
     */
    private function create(array $items, ?string $idempotencyKey, ?string $correlationId): BatchCreationResult
    {
        $batch = NotificationBatch::query()->create([
            'total_count' => 0,
            'idempotency_key' => $idempotencyKey,
            'correlation_id' => $correlationId,
        ]);

        $now = now();
        $rows = [];

        foreach ($items as $index => $data) {
            $rows[$index] = [
                'id' => (string) Str::uuid7(),
                'batch_id' => $batch->id,
                'batch_position' => $index,
                'channel' => $data->channel->value,
                'priority' => $data->priority->value,
                'recipient' => $data->recipient,
                'subject' => $data->subject,
                'content' => $data->content,
                'template_id' => $data->templateId,
                'template_variables' => $data->templateVariables !== null ? json_encode($data->templateVariables, JSON_THROW_ON_ERROR) : null,
                'status' => ($data->isScheduled() ? NotificationStatus::Scheduled : NotificationStatus::Pending)->value,
                'scheduled_at' => $data->scheduledAt,
                'idempotency_key' => $data->idempotencyKey,
                'correlation_id' => $correlationId,
                'attempts' => 0,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        // ON CONFLICT DO NOTHING: items whose idempotency key already exists
        // are skipped instead of failing the whole batch.
        foreach (array_chunk($rows, self::INSERT_CHUNK_SIZE) as $chunk) {
            Notification::query()->insertOrIgnore($chunk);
        }

        $inserted = Notification::query()
            ->where('batch_id', $batch->id)
            ->pluck('id')
            ->flip();

        $existingByKey = $this->existingIdsByKey($rows, $inserted->all());

        $result = [];
        foreach ($rows as $index => $row) {
            $isDuplicate = ! $inserted->has($row['id']);

            $result[] = [
                'index' => $index,
                'id' => $isDuplicate ? $existingByKey[(string) $row['idempotency_key']] : $row['id'],
                'duplicate' => $isDuplicate,
            ];
        }

        $batch->update(['total_count' => $inserted->count()]);

        return new BatchCreationResult($batch, $result, replayed: false);
    }

    /**
     * IDs of the earlier notifications that own the keys we could not insert.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @param  array<string, int>  $inserted
     * @return array<string, string>
     */
    private function existingIdsByKey(array $rows, array $inserted): array
    {
        $keys = [];
        foreach ($rows as $row) {
            if (! isset($inserted[$row['id']]) && is_string($row['idempotency_key'])) {
                $keys[] = $row['idempotency_key'];
            }
        }

        if ($keys === []) {
            return [];
        }

        /** @var array<string, string> */
        return Notification::query()
            ->whereIn('idempotency_key', $keys)
            ->pluck('id', 'idempotency_key')
            ->all();
    }

    private function findBatch(string $idempotencyKey): ?NotificationBatch
    {
        return NotificationBatch::query()->where('idempotency_key', $idempotencyKey)->first();
    }

    private function replay(NotificationBatch $batch): BatchCreationResult
    {
        // Items that were duplicates of earlier requests never joined the
        // batch, so a replay only lists the notifications the batch created.
        $items = array_values($batch->notifications()
            ->orderBy('batch_position')
            ->get(['id', 'batch_position'])
            ->map(fn (Notification $n): array => ['index' => (int) $n->batch_position, 'id' => $n->id, 'duplicate' => false])
            ->all());

        return new BatchCreationResult($batch, $items, replayed: true);
    }
}
