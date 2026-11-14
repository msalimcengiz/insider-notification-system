<?php

declare(strict_types=1);

namespace App\Services\Delivery;

use App\Models\Notification;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as Http;

/**
 * Provider that accepts notifications over a JSON webhook (webhook.site or
 * the bundled mock provider).
 *
 * The body is exactly the documented format. Tracing data travels in headers:
 * X-Correlation-ID for log correlation and Idempotency-Key (the notification
 * ID) so a provider can drop the duplicate if a worker crashes after the
 * provider accepted a message but before the result was stored.
 */
final readonly class WebhookChannelProvider implements ChannelProvider
{
    public function __construct(
        private Http $http,
        private ResponseClassifier $classifier,
        private string $url,
        private float $timeout,
        private float $connectTimeout,
    ) {}

    public function send(Notification $notification): DeliveryResult
    {
        $startedAt = hrtime(true);

        try {
            $response = $this->http
                ->acceptJson()
                ->asJson()
                ->timeout($this->timeout)
                ->connectTimeout($this->connectTimeout)
                ->withHeaders(array_filter([
                    'Idempotency-Key' => $notification->id,
                    'X-Correlation-ID' => $notification->correlation_id,
                ]))
                ->post($this->url, [
                    'to' => $notification->recipient,
                    'channel' => $notification->channel->value,
                    'content' => $notification->content,
                ]);
        } catch (ConnectionException $e) {
            return $this->classifier->connectionFailure($e->getMessage(), $this->elapsedMs($startedAt));
        }

        return $this->classifier->classify($response, $this->elapsedMs($startedAt));
    }

    private function elapsedMs(int|float $startedAt): int
    {
        return (int) round((hrtime(true) - $startedAt) / 1_000_000);
    }
}
