<?php

namespace App\Providers;

use App\Authorization\Permissions;
use App\Gateway\AssertionSigner;
use App\Gateway\GatewayClient;
use App\Http\Middleware\EnsureTwoFactorEnrolled;
use App\Http\Middleware\EnsureUserIsActive;
use App\Listeners\RecordAuthenticationEvents;
use App\Models\User;
use App\Services\CmsSettings;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use Livewire\Livewire;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(CmsSettings::class);

        $this->app->singleton(AssertionSigner::class, fn () => new AssertionSigner(
            secretKeyPath: config('services.gateway.assertion_secret_key_path'),
            kid: (string) config('services.gateway.assertion_kid', 'cms-1'),
            ttl: (int) config('services.gateway.assertion_ttl', AssertionSigner::MAX_TTL),
        ));

        // Not a singleton: the HTTP factory is resolved per use so Http::fake()
        // in tests is always honoured.
        $this->app->bind(GatewayClient::class, fn ($app) => new GatewayClient(
            http: $app->make(HttpFactory::class),
            signer: $app->make(AssertionSigner::class),
            baseUrl: config('services.gateway.url'),
            token: config('services.gateway.token'),
            connectTimeout: (int) config('services.gateway.connect_timeout', 5),
            timeout: (int) config('services.gateway.timeout', 15),
        ));
    }

    public function boot(): void
    {
        // One Gate ability per capability in docs/permissions.md.
        foreach (Permissions::capabilities() as $capability) {
            Gate::define($capability, fn (User $user, ?string $projectUuid = null) => Permissions::allows($user, $capability, $projectUuid));
        }

        Password::defaults(function () {
            $rule = Password::min(12)->letters()->mixedCase()->numbers();

            return $this->app->isProduction() ? $rule->uncompromised() : $rule;
        });

        Paginator::defaultView('pagination.default');
        Paginator::defaultSimpleView('pagination.simple');

        Event::subscribe(RecordAuthenticationEvents::class);

        // Re-apply portal middleware on Livewire update requests.
        Livewire::addPersistentMiddleware([
            EnsureUserIsActive::class,
            EnsureTwoFactorEnrolled::class,
        ]);
    }
}
