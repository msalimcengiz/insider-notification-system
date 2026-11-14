<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Enums\NotificationStatus;
use Illuminate\Http\JsonResponse;
use RuntimeException;

final class NotificationNotRetryable extends RuntimeException
{
    public function __construct(public readonly NotificationStatus $status)
    {
        parent::__construct("Only failed notifications can be retried; this one is \"{$status->value}\".");
    }

    public function render(): JsonResponse
    {
        return response()->json([
            'message' => $this->getMessage(),
            'status' => $this->status->value,
        ], 409);
    }
}
