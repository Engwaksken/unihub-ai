<?php

use App\Http\Middleware\AssignRequestId;
use App\Http\Middleware\AuthenticateManagement;
use App\Http\Middleware\EnforceJsonBody;
use App\Http\Middleware\EnsureSurface;
use App\Http\Middleware\ManagementIdempotency;
use App\Support\ApiException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\PostTooLargeException;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        commands: __DIR__.'/../routes/console.php',
        then: function () {
            // ADR D6: two surfaces, separated by vhost (GATEWAY_SURFACE) and re-checked here.
            Route::middleware(['gateway.public'])->group(base_path('routes/public.php'));
            Route::middleware(['gateway.internal'])->prefix('internal/v1')->group(base_path('routes/internal.php'));
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->group('gateway.public', [
            AssignRequestId::class,
            EnsureSurface::class.':public',
            EnforceJsonBody::class,
            SubstituteBindings::class,
        ]);
        $middleware->group('gateway.internal', [
            AssignRequestId::class,
            EnsureSurface::class.':internal',
            AuthenticateManagement::class,
            EnforceJsonBody::class,
            ManagementIdempotency::class,
            SubstituteBindings::class,
        ]);
        // Behind nginx on loopback; trust only the local proxy for client IPs.
        $middleware->trustProxies(at: ['127.0.0.1', '::1']);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Never log request bodies; prompts must not reach logs (PRD §08).
        $exceptions->dontFlash(['input', 'output', 'secret', 'password']);
        $exceptions->dontReport([ApiException::class]);

        // Every response is the PRD §06 error envelope — no stack traces, no internal URLs.
        $exceptions->render(fn (ApiException $e) => $e->render());
        $exceptions->render(fn (ValidationException $e) => ApiException::envelope('VALIDATION_FAILED', 'Invalid request', 422, ['fields' => $e->errors()]));
        $exceptions->render(fn (ThrottleRequestsException $e) => ApiException::envelope('RATE_LIMITED', 'Too many requests', 429, [], (int) ($e->getHeaders()['Retry-After'] ?? 60)));
        $exceptions->render(fn (PostTooLargeException $e) => ApiException::envelope('PAYLOAD_TOO_LARGE', 'Request body too large', 413));
        $exceptions->render(fn (NotFoundHttpException $e) => ApiException::envelope('NOT_FOUND', 'Not found', 404));
        $exceptions->render(fn (MethodNotAllowedHttpException $e) => ApiException::envelope('NOT_FOUND', 'Not found', 404));
        $exceptions->render(function (Throwable $e, Request $request) {
            if ($e instanceof HttpExceptionInterface) {
                return ApiException::envelope('INVALID_REQUEST', 'Request could not be processed', $e->getStatusCode());
            }
            if (config('app.debug')) {
                return null; // local debugging only; APP_DEBUG=false in every deployed env
            }

            return ApiException::envelope('SERVICE_UNAVAILABLE', 'Service error', 503, [], 30);
        });
    })->create();
