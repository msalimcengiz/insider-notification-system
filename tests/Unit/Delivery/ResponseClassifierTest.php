<?php

declare(strict_types=1);

use App\Enums\AttemptOutcome;
use App\Services\Delivery\ResponseClassifier;
use GuzzleHttp\Psr7\Response as Psr7Response;
use Illuminate\Http\Client\Response;

function providerResponse(int $status, mixed $body = null, array $headers = []): Response
{
    return new Response(new Psr7Response($status, $headers, $body === null ? '' : (is_string($body) ? $body : json_encode($body))));
}

it('treats 202 with a messageId as success', function (): void {
    $result = (new ResponseClassifier)->classify(
        providerResponse(202, ['messageId' => 'abc', 'status' => 'accepted', 'timestamp' => '2026-01-01T00:00:00Z']),
        42,
    );

    expect($result->outcome)->toBe(AttemptOutcome::Success)
        ->and($result->providerMessageId)->toBe('abc')
        ->and($result->httpStatus)->toBe(202)
        ->and($result->durationMs)->toBe(42);
});

it('retries a 202 that does not match the documented response', function (mixed $body): void {
    expect((new ResponseClassifier)->classify(providerResponse(202, $body), 1)->outcome)
        ->toBe(AttemptOutcome::RetryableFailure);
})->with([
    'no body' => [null],
    'not json' => ['<html>ok</html>'],
    'no messageId' => [['status' => 'accepted']],
    'empty messageId' => [['messageId' => '']],
    'status not accepted' => [['messageId' => 'abc', 'status' => 'queued']],
]);

it('only treats 202 Accepted as acceptance', function (int $status): void {
    $result = (new ResponseClassifier)->classify(
        providerResponse($status, ['messageId' => 'abc', 'status' => 'accepted']),
        1,
    );

    expect($result->outcome)->toBe(AttemptOutcome::RetryableFailure)
        ->and($result->error)->toBe("Provider answered {$status} but the expected status is 202 Accepted.");
})->with([200, 201, 204]);

it('reports an unconfigured webhook.site URL clearly', function (): void {
    // What webhook.site answers before its response is configured.
    $result = (new ResponseClassifier)->classify(providerResponse(200, 'This URL has no default content configured.'), 1);

    expect($result->outcome)->toBe(AttemptOutcome::RetryableFailure)
        ->and($result->error)->toContain('expected status is 202');
});

it('classifies error statuses', function (int $status, AttemptOutcome $outcome): void {
    expect((new ResponseClassifier)->classify(providerResponse($status, ['message' => 'x']), 1)->outcome)->toBe($outcome);
})->with([
    [500, AttemptOutcome::RetryableFailure],
    [502, AttemptOutcome::RetryableFailure],
    [503, AttemptOutcome::RetryableFailure],
    [429, AttemptOutcome::RetryableFailure],
    [408, AttemptOutcome::RetryableFailure],
    [400, AttemptOutcome::PermanentFailure],
    [401, AttemptOutcome::PermanentFailure],
    [404, AttemptOutcome::PermanentFailure],
    [422, AttemptOutcome::PermanentFailure],
]);

it('keeps the provider error message', function (): void {
    $result = (new ResponseClassifier)->classify(providerResponse(400, ['message' => 'Invalid number']), 1);

    expect($result->error)->toBe('Provider answered 400. Invalid number');
});

it('reads Retry-After in seconds', function (): void {
    $result = (new ResponseClassifier)->classify(providerResponse(429, null, ['Retry-After' => '30']), 1);

    expect($result->retryAfter)->toBe(30);
});

it('treats connection failures as retryable', function (): void {
    $result = (new ResponseClassifier)->connectionFailure('cURL error 28: timed out', 5000);

    expect($result->outcome)->toBe(AttemptOutcome::RetryableFailure)
        ->and($result->httpStatus)->toBeNull()
        ->and($result->error)->toContain('timed out');
});
