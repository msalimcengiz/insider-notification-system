<?php

declare(strict_types=1);

namespace App\DTOs;

use App\Enums\Channel;
use App\Enums\Priority;
use Carbon\CarbonImmutable;

/**
 * A validated, fully rendered notification request.
 */
final readonly class NotificationData
{
    /**
     * @param  array<string, scalar|null>|null  $templateVariables
     */
    public function __construct(
        public Channel $channel,
        public Priority $priority,
        public string $recipient,
        public string $content,
        public ?string $subject = null,
        public ?string $templateId = null,
        public ?array $templateVariables = null,
        public ?CarbonImmutable $scheduledAt = null,
        public ?string $idempotencyKey = null,
    ) {}

    public function isScheduled(): bool
    {
        return $this->scheduledAt?->isFuture() ?? false;
    }

    /**
     * Whether a stored notification describes the same message. Used to
     * detect an idempotency key being reused for a different request.
     */
    public function matches(string $channel, string $recipient, string $content): bool
    {
        return $this->channel->value === $channel
            && $this->recipient === $recipient
            && $this->content === $content;
    }
}
