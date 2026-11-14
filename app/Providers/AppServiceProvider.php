<?php

declare(strict_types=1);

namespace App\Providers;

use App\Enums\Channel;
use App\Services\CircuitBreaker\CircuitBreaker;
use App\Services\Delivery\ChannelProvider;
use App\Services\Delivery\ProviderRegistry;
use App\Services\Delivery\ResponseClassifier;
use App\Services\Delivery\RetryPolicy;
use App\Services\Delivery\WebhookChannelProvider;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Redis\Factory as Redis;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Client\Factory as Http;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(RetryPolicy::class, fn (): RetryPolicy => RetryPolicy::fromConfig());
        $this->app->singleton(CircuitBreaker::class, fn (Application $app): CircuitBreaker => CircuitBreaker::fromConfig($app->make(Redis::class)));

        $this->app->singleton(ProviderRegistry::class, function (Application $app): ProviderRegistry {
            $factories = [];

            foreach (Channel::cases() as $channel) {
                $factories[$channel->value] = fn (): ChannelProvider => $this->webhookProvider($app, $channel);
            }

            return new ProviderRegistry($factories);
        });
    }

    public function boot(): void
    {
        Date::use(CarbonImmutable::class);

        // Fail loudly on lazy loading, silently discarded attributes and
        // missing attributes everywhere except production.
        Model::shouldBeStrict(! $this->app->isProduction());
    }

    private function webhookProvider(Application $app, Channel $channel): WebhookChannelProvider
    {
        $config = (array) config('notifications.provider');
        $channelUrl = config("notifications.provider.channels.{$channel->value}.url");

        return new WebhookChannelProvider(
            $app->make(Http::class),
            $app->make(ResponseClassifier::class),
            url: is_string($channelUrl) && $channelUrl !== '' ? $channelUrl : (string) ($config['url'] ?? ''),
            timeout: (float) ($config['timeout'] ?? 5),
            connectTimeout: (float) ($config['connect_timeout'] ?? 2),
        );
    }
}
