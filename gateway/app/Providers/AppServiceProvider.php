<?php

namespace App\Providers;

use App\Models\ApiKey;
use App\Services\Ollama\OllamaClient;
use App\Services\Secrets;
use App\Services\Settings;
use Carbon\CarbonImmutable;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(Secrets::class, fn () => new Secrets((string) config('gateway.secrets_path')));
        $this->app->scoped(Settings::class);
        $this->app->singleton(OllamaClient::class, fn () => new OllamaClient);
    }

    public function boot(): void
    {
        // All dates immutable: no accidental mutation in lease/quota arithmetic.
        Date::use(CarbonImmutable::class);

        // Per-key request rate (polling + submissions). Quotas are enforced separately and atomically.
        RateLimiter::for('project-key', function (Request $request) {
            $key = $request->attributes->get('api_key');

            return Limit::perMinute(app(Settings::class)->int('poll_rate_per_minute'))
                ->by($key instanceof ApiKey ? 'key:'.$key->id : 'ip:'.$request->ip());
        });
    }
}
