<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | API limits
    |--------------------------------------------------------------------------
    */

    'batch' => [
        'max_size' => (int) env('NOTIFICATIONS_BATCH_MAX_SIZE', 1000),
    ],

    // How far into the future a notification may be scheduled.
    'max_schedule_days' => (int) env('NOTIFICATIONS_MAX_SCHEDULE_DAYS', 30),

    /*
    |--------------------------------------------------------------------------
    | Content limits per channel
    |--------------------------------------------------------------------------
    |
    | SMS is measured in segments rather than characters: a GSM-7 message fits
    | 160 characters in one segment (153 per segment when concatenated), a
    | message containing any non GSM-7 character is UCS-2 encoded and fits 70
    | (67 when concatenated). Long messages are capped at `max_segments`.
    |
    */

    'content' => [
        'sms' => [
            'max_segments' => (int) env('NOTIFICATIONS_SMS_MAX_SEGMENTS', 10),
        ],
        'email' => [
            'max_length' => 100_000,
            'max_subject_length' => 255,
        ],
        'push' => [
            'max_length' => 1_000,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Delivery provider
    |--------------------------------------------------------------------------
    |
    | As the assessment specifies, notifications go to webhook.site: every
    | channel posts {"to", "channel", "content"} and expects
    | 202 {"messageId", "status": "accepted", "timestamp"}. The URL can be
    | overridden globally or per channel through the environment.
    |
    */

    'provider' => [
        'url' => env('NOTIFICATION_PROVIDER_URL', 'https://webhook.site/d578e70a-82cf-4fb9-9762-938e0c61d818'),
        'channels' => [
            'sms' => ['url' => env('NOTIFICATION_PROVIDER_SMS_URL')],
            'email' => ['url' => env('NOTIFICATION_PROVIDER_EMAIL_URL')],
            'push' => ['url' => env('NOTIFICATION_PROVIDER_PUSH_URL')],
        ],
        'timeout' => (float) env('NOTIFICATION_PROVIDER_TIMEOUT', 5),
        'connect_timeout' => (float) env('NOTIFICATION_PROVIDER_CONNECT_TIMEOUT', 2),
    ],

    /*
    |--------------------------------------------------------------------------
    | Rate limiting
    |--------------------------------------------------------------------------
    |
    | Sliding window, shared by every worker through Redis: no one-second
    | window ever contains more than `per_second` provider calls per channel.
    | A worker that finds the window full waits (up to `max_wait_ms`) instead
    | of releasing the job, which keeps the queue order and priorities intact.
    |
    */

    'rate_limit' => [
        'per_second' => (int) env('NOTIFICATIONS_RATE_LIMIT_PER_SECOND', 100),
        // The guarantee holds when calls leave the worker. Measured where
        // they *arrive*, transport jitter can push a window slightly over the
        // limit (the load test saw up to 109/s on a CPU-starved host). For a
        // provider that enforces the limit strictly on arrival, add headroom:
        // e.g. 1100 means 100 calls per 1.1 s.
        'window_ms' => (int) env('NOTIFICATIONS_RATE_LIMIT_WINDOW_MS', 1000),
        'max_wait_ms' => (int) env('NOTIFICATIONS_RATE_LIMIT_MAX_WAIT_MS', 2000),
    ],

    /*
    |--------------------------------------------------------------------------
    | Retries
    |--------------------------------------------------------------------------
    |
    | Exponential backoff with jitter for retryable failures (timeouts,
    | connection errors, 429, 5xx, malformed 2xx). Delay before retry n:
    | min(max_delay, base_delay * multiplier^(n-1)), randomised by ±jitter.
    | A Retry-After header from the provider is honoured when it is longer.
    |
    */

    'retry' => [
        'max_attempts' => (int) env('NOTIFICATIONS_MAX_ATTEMPTS', 5),
        'base_delay' => (int) env('NOTIFICATIONS_RETRY_BASE_DELAY', 10),
        'multiplier' => (float) env('NOTIFICATIONS_RETRY_MULTIPLIER', 3),
        'max_delay' => (int) env('NOTIFICATIONS_RETRY_MAX_DELAY', 900),
        'jitter' => (float) env('NOTIFICATIONS_RETRY_JITTER', 0.2),
    ],

    /*
    |--------------------------------------------------------------------------
    | Circuit breaker
    |--------------------------------------------------------------------------
    |
    | After `failure_threshold` consecutive transient failures on a channel
    | (within `failure_window_seconds`) the channel's circuit opens for
    | `cooldown_seconds`; then a single probe call decides whether it closes.
    |
    */

    'circuit_breaker' => [
        'failure_threshold' => (int) env('NOTIFICATIONS_CIRCUIT_FAILURE_THRESHOLD', 10),
        'cooldown_seconds' => (int) env('NOTIFICATIONS_CIRCUIT_COOLDOWN_SECONDS', 30),
        'failure_window_seconds' => (int) env('NOTIFICATIONS_CIRCUIT_FAILURE_WINDOW_SECONDS', 60),
    ],

    /*
    |--------------------------------------------------------------------------
    | Recovery sweeper
    |--------------------------------------------------------------------------
    |
    | PostgreSQL is the source of truth. Every minute the sweeper re-dispatches
    | notifications that should be moving but are not, e.g. because a worker
    | died mid-delivery or Redis lost a job. Duplicate jobs are harmless: a job
    | only proceeds if it can claim its notification.
    |
    */

    'recovery' => [
        // Persisted but never handed to the queue.
        'pending_after_seconds' => (int) env('NOTIFICATIONS_RECOVER_PENDING_AFTER', 60),
        // Waiting in a queue for much longer than any realistic backlog.
        'queued_after_seconds' => (int) env('NOTIFICATIONS_RECOVER_QUEUED_AFTER', 900),
        // Claimed by a worker that never reported back (job timeout is 30s).
        'processing_after_seconds' => (int) env('NOTIFICATIONS_RECOVER_PROCESSING_AFTER', 300),
        // Retry was due but its delayed job never ran.
        'retrying_overdue_seconds' => (int) env('NOTIFICATIONS_RECOVER_RETRYING_OVERDUE', 300),
        'max_per_run' => (int) env('NOTIFICATIONS_RECOVER_MAX_PER_RUN', 5000),
    ],

    /*
    |--------------------------------------------------------------------------
    | Retention
    |--------------------------------------------------------------------------
    |
    | Finished notifications (sent, failed, cancelled) and their attempt
    | history are deleted after this many days by the daily `model:prune`
    | run, so the tables stay bounded on a server that runs for months.
    | Notifications still in progress are never pruned.
    |
    */

    'retention_days' => (int) env('NOTIFICATIONS_RETENTION_DAYS', 30),

];
