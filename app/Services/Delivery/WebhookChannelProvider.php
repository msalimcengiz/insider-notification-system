<?php

declare(strict_types=1);

namespace App\Services\Delivery;

use App\Models\Notification;
use App\Support\Tracing\Tracing;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as Http;
use OpenTelemetry\API\Trace\SpanInterface;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;

/**
 * Provider that accepts notifications over a JSON webhook (webhook.site or
 * the bundled mock provider).
 *
 * The body is exactly the documented format. Everything else travels in
 * headers: X-Correlation-ID for log correlation, `traceparent` for
 * distributed tracing, and Idempotency-Key (the notification ID) so a
 * provider can drop the duplicate if a worker crashes after the provider
 * accepted a message but before the result was stored.
 */
final readonly class WebhookChannelProvider implements ChannelProvider
{
    public function __construct(
        private Http $http,
        private ResponseClassifier $classifier,
        private string $url,
        private float $timeout,
        private float $connectTimeout,
        private Tracing $tracing,
    ) {}

    public function send(Notification $notification): DeliveryResult
    {
        $host = parse_url($this->url, PHP_URL_HOST);

        // Client span; the W3C `traceparent` header lets a tracing-aware
        // provider continue the trace. The request body stays exactly the
        // documented {to, channel, content}.
        return $this->tracing->inSpan(is_string($host) ? "POST {$host}" : 'POST', function (SpanInterface $span) use ($notification): DeliveryResult {
            $result = $this->call($notification);

            $span->setAttributes(array_filter([
                'http.response.status_code' => $result->httpStatus,
                'notification.outcome' => $result->outcome->value,
                'provider.message_id' => $result->providerMessageId,
                'error.type' => $result->succeeded() ? null : ($result->httpStatus !== null ? (string) $result->httpStatus : 'connection'),
            ], fn (mixed $value): bool => $value !== null));

            if (! $result->succeeded()) {
                $span->setStatus(StatusCode::STATUS_ERROR, (string) $result->error);
            }

            return $result;
        }, [
            'http.request.method' => 'POST',
            'url.full' => $this->url,
            'server.address' => is_string($host) ? $host : null,
            'notification.id' => $notification->id,
            'notification.channel' => $notification->channel->value,
        ], SpanKind::KIND_CLIENT);
    }

    private function call(Notification $notification): DeliveryResult
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
                    ...$this->tracing->currentCarrier(),
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
