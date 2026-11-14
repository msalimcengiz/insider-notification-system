<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\ValidationException;

/**
 * @mixin FormRequest
 */
trait ReadsIdempotencyKey
{
    public const string IDEMPOTENCY_HEADER = 'Idempotency-Key';

    public function idempotencyKey(): ?string
    {
        $key = $this->header(self::IDEMPOTENCY_HEADER);

        if (! is_string($key) || $key === '') {
            return null;
        }

        if (strlen($key) > 255) {
            throw ValidationException::withMessages([
                self::IDEMPOTENCY_HEADER => 'The Idempotency-Key header may not be longer than 255 characters.',
            ]);
        }

        return $key;
    }
}
