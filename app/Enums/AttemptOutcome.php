<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Result of a single delivery attempt against the provider.
 */
enum AttemptOutcome: string
{
    case Success = 'success';

    /** Timeout, connection error, 5xx, 429 or malformed 2xx response. */
    case RetryableFailure = 'retryable_failure';

    /** Any other 4xx: retrying would produce the same result. */
    case PermanentFailure = 'permanent_failure';
}
