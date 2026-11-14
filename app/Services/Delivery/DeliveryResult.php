<?php

declare(strict_types=1);

namespace App\Services\Delivery;

use App\Enums\AttemptOutcome;

/**
 * What happened during one provider call.
 */
final readonly class DeliveryResult
{
    /**
     * @param  array<string, mixed>|null  $responseBody
     */
    public function __construct(
        public AttemptOutcome $outcome,
        public int $durationMs,
        public ?int $httpStatus = null,
        public ?string $providerMessageId = null,
        public ?string $error = null,
        public ?array $responseBody = null,
        /** Seconds, from a Retry-After response header. */
        public ?int $retryAfter = null,
    ) {}

    public function succeeded(): bool
    {
        return $this->outcome === AttemptOutcome::Success;
    }

    public function isRetryable(): bool
    {
        return $this->outcome === AttemptOutcome::RetryableFailure;
    }
}
