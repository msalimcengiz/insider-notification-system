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
use App\Support\Tracing\Tracing;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Redis\Factory as Redis;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\Client\Factory as Http;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ServiceProvider;
use OpenTelemetry\API\Globals;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(RetryPolicy::class, fn (): RetryPolicy => RetryPolicy::fromConfig());
        // Globals is configured by the OpenTelemetry SDK autoloader from the
        // OTEL_* environment; without it these are no-op implementations.
        $this->app->singleton(Tracing::class, fn (): Tracing => new Tracing(Globals::tracerProvider(), Globals::propagator()));
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

        $this->traceDatabaseQueries();
    }

    /**
     * One client span per SQL query, as a child of whatever is being traced
     * (an HTTP request, a job). Queries outside a trace are not recorded, and
     * only the statement is kept: bindings may contain personal data.
     */
    private function traceDatabaseQueries(): void
    {
        $tracing = $this->app->make(Tracing::class);

        if (! $tracing->enabled()) {
            return;
        }

        DB::listen(function (QueryExecuted $query) use ($tracing): void {
            if (! $tracing->hasActiveSpan()) {
                return;
            }

            $operation = strtoupper(strtok(ltrim($query->sql), " \n\t") ?: 'QUERY');
            $table = preg_match('/\b(?:from|into|update|join)\s+"?([a-z_][a-z0-9_]*)"?/i', $query->sql, $match) === 1 ? $match[1] : null;

            $tracing->recordCompleted(
                $table !== null ? "{$operation} {$table}" : $operation,
                $query->time ?? 0.0,
                [
                    'db.system.name' => 'postgresql',
                    'db.namespace' => $query->connection->getDatabaseName(),
                    'db.operation.name' => $operation,
                    'db.collection.name' => $table,
                    'db.query.text' => $query->sql,
                ],
            );
        });
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
            tracing: $app->make(Tracing::class),
        );
    }
}
