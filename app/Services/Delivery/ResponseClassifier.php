<?php

declare(strict_types=1);

namespace App\Services\Delivery;

use App\Enums\AttemptOutcome;
use Illuminate\Http\Client\Response;

/**
 * Turns a provider response into a delivery outcome.
 *
 * The assessment specifies the acceptance response exactly:
 * 202 {"messageId", "status": "accepted", "timestamp"}. Only that counts as
 * success; anything else in the 2xx range cannot prove the message was
 * accepted and is retried.
 *
 *  - 202 with a messageId and status "accepted"   → success
 *  - any other 2xx, or 202 not matching the above → retryable
 *  - 408, 425, 429, 5xx                           → retryable (transient)
 *  - any other 4xx / unexpected status            → permanent (retrying won't help)
 *  - timeout / connection error                   → retryable
 */
final class ResponseClassifier
{
    private const int ACCEPTED = 202;

    private const array RETRYABLE_STATUSES = [408, 425, 429];

    private const int MAX_STORED_ERROR_LENGTH = 1000;

    public function classify(Response $response, int $durationMs): DeliveryResult
    {
        $status = $response->status();
        $body = $this->jsonBody($response);

        if ($response->successful()) {
            $problem = $this->acceptanceProblem($status, $body);

            if ($problem === null) {
                /** @var string $messageId */
                $messageId = $body['messageId'] ?? '';

                return new DeliveryResult(AttemptOutcome::Success, $durationMs, $status, $messageId, responseBody: $body);
            }

            return new DeliveryResult(
                AttemptOutcome::RetryableFailure,
                $durationMs,
                $status,
                error: "Provider answered {$status} but {$problem}.",
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

    /**
     * Why a 2xx response is not the documented acceptance, or null if it is.
     *
     * @param  array<string, mixed>|null  $body
     */
    private function acceptanceProblem(int $status, ?array $body): ?string
    {
        if ($status !== self::ACCEPTED) {
            return 'the expected status is 202 Accepted';
        }

        $messageId = $body['messageId'] ?? null;
        if ($body === null || ! is_string($messageId) || $messageId === '') {
            return 'the response has no messageId';
        }

        if (array_key_exists('status', $body) && $body['status'] !== 'accepted') {
            return 'the response status is not "accepted"';
        }

        return null;
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
