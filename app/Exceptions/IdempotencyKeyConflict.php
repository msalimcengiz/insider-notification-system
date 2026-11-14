<?php

declare(strict_types=1);

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * An idempotency key was reused for a request with a different payload.
 */
final class IdempotencyKeyConflict extends RuntimeException
{
    public function __construct(public readonly string $key)
    {
        parent::__construct("The idempotency key \"{$key}\" was already used for a different request.");
    }

    public function render(): JsonResponse
    {
        return response()->json(['message' => $this->getMessage()], 409);
    }
}
