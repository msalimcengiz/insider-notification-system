<?php

declare(strict_types=1);

use App\Enums\NotificationStatus as Status;

it('allows the happy path', function (): void {
    expect(Status::Scheduled->canTransitionTo(Status::Pending))->toBeTrue()
        ->and(Status::Pending->canTransitionTo(Status::Queued))->toBeTrue()
        ->and(Status::Queued->canTransitionTo(Status::Processing))->toBeTrue()
        ->and(Status::Processing->canTransitionTo(Status::Sent))->toBeTrue();
});

it('allows retrying and giving up from processing', function (): void {
    expect(Status::Processing->canTransitionTo(Status::Retrying))->toBeTrue()
        ->and(Status::Retrying->canTransitionTo(Status::Processing))->toBeTrue()
        ->and(Status::Processing->canTransitionTo(Status::Failed))->toBeTrue();
});

it('allows a failed notification to be re-queued manually', function (): void {
    expect(Status::Failed->canTransitionTo(Status::Queued))->toBeTrue();
});

it('lets the recovery sweeper re-queue stuck notifications', function (): void {
    expect(Status::Processing->canTransitionTo(Status::Queued))->toBeTrue()
        ->and(Status::Retrying->canTransitionTo(Status::Queued))->toBeTrue();
});

it('never leaves sent or cancelled', function (Status $final): void {
    expect($final->allowedTransitions())->toBe([]);
})->with([Status::Sent, Status::Cancelled]);

it('rejects skipping steps', function (Status $from, Status $to): void {
    expect($from->canTransitionTo($to))->toBeFalse();
})->with([
    'pending → sent' => [Status::Pending, Status::Sent],
    'queued → sent' => [Status::Queued, Status::Sent],
    'scheduled → processing' => [Status::Scheduled, Status::Processing],
    'sent → queued' => [Status::Sent, Status::Queued],
    'failed → sent' => [Status::Failed, Status::Sent],
]);

it('only cancels notifications that have not reached a worker', function (): void {
    expect(Status::cancellable())->toBe([
        Status::Scheduled,
        Status::Pending,
        Status::Queued,
        Status::Retrying,
    ])->and(Status::Processing->isCancellable())->toBeFalse();
});

it('flags final statuses', function (): void {
    $final = array_values(array_filter(Status::cases(), fn (Status $s): bool => $s->isFinal()));

    expect($final)->toBe([Status::Sent, Status::Failed, Status::Cancelled]);
});
