<?php

use App\Gateway\Exceptions\GatewayAuthenticationException;
use App\Gateway\Exceptions\GatewayForbiddenException;
use App\Gateway\Exceptions\GatewayNotFoundException;
use App\Gateway\Exceptions\GatewayUnavailableException;
use App\Gateway\Exceptions\GatewayValidationException;
use App\Http\Middleware\AssignRequestId;
use App\Http\Middleware\EnsureFeatureEnabled;
use App\Http\Middleware\EnsureTwoFactorEnrolled;
use App\Http\Middleware\EnsureUserIsActive;
use App\Http\Middleware\SecurityHeaders;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->prepend(AssignRequestId::class);
        $middleware->web(append: [
            SecurityHeaders::class,
            EnsureUserIsActive::class,
        ]);
        $middleware->alias([
            'mfa' => EnsureTwoFactorEnrolled::class,
            'active' => EnsureUserIsActive::class,
            'feature' => EnsureFeatureEnabled::class,
        ]);
        // cPanel/shared hosting usually terminates TLS in front of PHP.
        $middleware->trustProxies(at: env('TRUSTED_PROXIES') ? explode(',', (string) env('TRUSTED_PROXIES')) : null);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // Gateway errors surface back onto the form fields with the same name.
        $exceptions->map(GatewayValidationException::class, fn (GatewayValidationException $e) => ValidationException::withMessages($e->fieldErrors()));

        // Out-of-scope resources are indistinguishable from unknown ones.
        $exceptions->map(GatewayNotFoundException::class, fn (GatewayNotFoundException $e) => new NotFoundHttpException('Not found.', $e));
        $exceptions->map(GatewayForbiddenException::class, fn (GatewayForbiddenException $e) => new AccessDeniedHttpException('This action is not allowed for your role.', $e));

        $exceptions->render(function (GatewayUnavailableException|GatewayAuthenticationException $e, Request $request) {
            if ($request->expectsJson()) {
                return response()->json(['message' => 'Gateway unreachable.'], 503);
            }

            return response()->view('errors.gateway', ['exception' => $e], 503);
        });

        $exceptions->dontReport([
            GatewayValidationException::class,
            GatewayNotFoundException::class,
            GatewayForbiddenException::class,
        ]);
    })->create();
