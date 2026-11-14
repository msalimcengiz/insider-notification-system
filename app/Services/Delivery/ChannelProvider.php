<?php

declare(strict_types=1);

namespace App\Services\Delivery;

use App\Models\Notification;

/**
 * Sends one notification through an external provider.
 *
 * Implementations never throw for delivery problems: every outcome,
 * including timeouts and connection errors, is returned as a DeliveryResult
 * so the caller can record the attempt and decide whether to retry.
 */
interface ChannelProvider
{
    public function send(Notification $notification): DeliveryResult;
}
