<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Enums\NotificationStatus;
use Illuminate\Http\JsonResponse;
use RuntimeException;

final class NotificationNotCancellable extends RuntimeException
{
    public function __construct(public readonly NotificationStatus $status)
    {
        parent::__construct("A notification in the \"{$status->value}\" status can no longer be cancelled.");
    }

    public function render(): JsonResponse
    {
        return response()->json([
            'message' => $this->getMessage(),
            'status' => $this->status->value,
            'cancellable_statuses' => array_map(
                fn (NotificationStatus $status): string => $status->value,
                NotificationStatus::cancellable(),
            ),
        ], 409);
    }
}
