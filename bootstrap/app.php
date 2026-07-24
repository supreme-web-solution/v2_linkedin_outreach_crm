<?php

use App\Http\Middleware\HandleAppearance;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\ResolveViteAssets;
use App\Http\Middleware\SetRequestRootUrl;
use App\V2\Integrations\Unipile\UnipileException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->trustProxies(at: '*');

        $middleware->web(prepend: [
            SetRequestRootUrl::class,
            ResolveViteAssets::class,
        ]);

        $middleware->encryptCookies(except: ['appearance', 'sidebar_state']);

        $middleware->web(append: [
            HandleAppearance::class,
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);

        $middleware->alias([
            'v2.extension.token' => \App\Http\Middleware\EnsureV2ExtensionToken::class,
            'v2.idempotency' => \App\Http\Middleware\EnsureIdempotencyKey::class,
            'v2.tenant' => \App\Http\Middleware\EnsureV2TenantContext::class,
            'v2.capability' => \App\Http\Middleware\EnsureV2Capability::class,
            'entitlement' => \App\Http\Middleware\EnsureEntitlement::class,
            'platform.admin' => \App\Http\Middleware\EnsurePlatformAdmin::class,
            'reseller' => \App\Http\Middleware\EnsureReseller::class,
        ]);

        $middleware->validateCsrfTokens(except: [
            'webhooks/jvzoo',
            'unipile/callback',
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );

        $exceptions->render(function (UnipileException $exception, Request $request) {
            if (!$request->is('api/*')) {
                return null;
            }

            return response()->json([
                'message' => $exception->getMessage(),
                'hint' => $exception->context['hint'] ?? null,
                'error_code' => $exception->context['error_code'] ?? null,
                'provider' => 'unipile',
                'context' => app()->isProduction() ? null : $exception->context,
            ], $exception->statusCode >= 400 && $exception->statusCode < 600 ? $exception->statusCode : 502);
        });
    })->create();
