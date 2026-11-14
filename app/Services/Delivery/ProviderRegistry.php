<?php

declare(strict_types=1);

namespace App\Services\Delivery;

use App\Enums\Channel;
use Closure;

/**
 * Resolves the provider of a channel (strategy per channel). Adding a channel
 * or swapping one provider for another only touches the registration in
 * AppServiceProvider.
 */
final class ProviderRegistry
{
    /** @var array<string, ChannelProvider> */
    private array $resolved = [];

    /**
     * @param  array<string, Closure(): ChannelProvider>  $factories  channel value => factory
     */
    public function __construct(private readonly array $factories) {}

    public function for(Channel $channel): ChannelProvider
    {
        return $this->resolved[$channel->value] ??= ($this->factories[$channel->value]
            ?? throw new \InvalidArgumentException("No provider registered for the {$channel->value} channel."))();
    }
}
