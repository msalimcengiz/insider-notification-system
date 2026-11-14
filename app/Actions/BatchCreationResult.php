<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\NotificationBatch;

final readonly class BatchCreationResult
{
    /**
     * @param  list<array{index: int, id: string, duplicate: bool}>  $items  one entry per request item, in request order
     */
    public function __construct(
        public NotificationBatch $batch,
        public array $items,
        public bool $replayed,
    ) {}

    public function acceptedCount(): int
    {
        return count(array_filter($this->items, fn (array $item): bool => ! $item['duplicate']));
    }

    public function duplicateCount(): int
    {
        return count($this->items) - $this->acceptedCount();
    }
}
