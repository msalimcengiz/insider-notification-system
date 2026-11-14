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

];
