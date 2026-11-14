<?php

declare(strict_types=1);

namespace App\Services\CircuitBreaker;

enum CircuitState: string
{
    /** Normal operation. */
    case Closed = 'closed';

    /** Provider considered down: calls are not attempted. */
    case Open = 'open';

    /** Cool-down over: a single probe call decides whether to close or re-open. */
    case HalfOpen = 'half_open';
}
