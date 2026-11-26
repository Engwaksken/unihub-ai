<?php

namespace Unihub\CentralAi;

use Illuminate\Support\ServiceProvider;

class CentralAiServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/central-ai.php', 'central-ai');

        $this->app->singleton(CentralAiClient::class, fn ($app) => new CentralAiClient(
            (string) $app['config']['central-ai.url'],
            (string) $app['config']['central-ai.key'],
            (int) $app['config']['central-ai.connect_timeout'],
            (int) $app['config']['central-ai.timeout'],
        ));
        $this->app->singleton(AiRequestService::class, fn ($app) => new AiRequestService(
            $app->make(CentralAiClient::class),
            (int) $app['config']['central-ai.min_poll_interval'],
            (int) $app['config']['central-ai.client_deadline'],
        ));
    }

    public function boot(): void
    {
        $this->publishes([__DIR__.'/../config/central-ai.php' => config_path('central-ai.php')], 'central-ai-config');
        $this->publishesMigrations([__DIR__.'/../database/migrations' => database_path('migrations')], 'central-ai-migrations');
    }
}
