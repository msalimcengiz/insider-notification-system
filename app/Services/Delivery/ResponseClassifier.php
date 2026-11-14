<?php

declare(strict_types=1);

namespace App\Services\Delivery;

use App\Enums\AttemptOutcome;
use Illuminate\Http\Client\Response;

/**
 * Turns a provider response into a delivery outcome.
 *
 *  - 2xx with a messageId                → success
 *  - 2xx without a usable messageId      → retryable (cannot prove acceptance)
 *  - 408, 425, 429, 5xx                  → retryable (transient)
 *  - any other 4xx / unexpected status   → permanent (retrying won't help)
 *  - timeout / connection error          → retryable
 */
final class ResponseClassifier
{
    private const array RETRYABLE_STATUSES = [408, 425, 429];

    private const int MAX_STORED_ERROR_LENGTH = 1000;

    public function classify(Response $response, int $durationMs): DeliveryResult
    {
        $status = $response->status();
        $body = $this->jsonBody($response);

        if ($response->successful()) {
            $messageId = $body['messageId'] ?? null;

            if (is_string($messageId) && $messageId !== '') {
                return new DeliveryResult(AttemptOutcome::Success, $durationMs, $status, $messageId, responseBody: $body);
            }

            return new DeliveryResult(
                AttemptOutcome::RetryableFailure,
                $durationMs,
                $status,
                error: "Provider answered {$status} without a messageId.",
                responseBody: $body,
            );
        }

        $retryable = $response->serverError() || in_array($status, self::RETRYABLE_STATUSES, true);

        return new DeliveryResult(
            $retryable ? AttemptOutcome::RetryableFailure : AttemptOutcome::PermanentFailure,
            $durationMs,
            $status,
            error: $this->errorMessage($status, $response, $body),
            responseBody: $body,
            retryAfter: $this->retryAfter($response),
        );
    }

    public function connectionFailure(string $message, int $durationMs): DeliveryResult
    {
        return new DeliveryResult(
            AttemptOutcome::RetryableFailure,
            $durationMs,
            error: 'Connection failed: '.mb_substr($message, 0, self::MAX_STORED_ERROR_LENGTH),
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    private function jsonBody(Response $response): ?array
    {
        $decoded = json_decode($response->body(), true);

        if (! is_array($decoded)) {
            return null;
        }

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    /**
     * @param  array<string, mixed>|null  $body
     */
    private function errorMessage(int $status, Response $response, ?array $body): string
    {
        $detail = $body['message'] ?? $body['error'] ?? null;
        $detail = is_string($detail) ? $detail : mb_substr(trim($response->body()), 0, 200);

        return mb_substr(trim("Provider answered {$status}. {$detail}"), 0, self::MAX_STORED_ERROR_LENGTH);
    }

    private function retryAfter(Response $response): ?int
    {
        $header = $response->header('Retry-After');

        if (is_numeric($header)) {
            return max(0, (int) $header);
        }

        if ($header !== '' && ($timestamp = strtotime($header)) !== false) {
            return max(0, $timestamp - time());
        }

        return null;
    }
}
