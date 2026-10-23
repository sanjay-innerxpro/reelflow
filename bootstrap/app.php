<?php

use App\Http\Middleware\AuthenticateDevice;
use App\Http\Middleware\EnsureAdmin;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\SetCurrentWorkspace;
use App\Support\Credentials;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->redirectGuestsTo(fn () => route('login'));
        $middleware->redirectUsersTo(fn () => route('dashboard'));
        $middleware->trustProxies(at: env('TRUSTED_PROXIES', '127.0.0.1'));
        $middleware->append(SecurityHeaders::class);
        $middleware->alias(['workspace' => SetCurrentWorkspace::class, 'admin' => EnsureAdmin::class]);
        // Tenant scoping must be active before route-model binding resolves IDs.
        $middleware->prependToPriorityList(SubstituteBindings::class, SetCurrentWorkspace::class);
        $middleware->prependToPriorityList(SubstituteBindings::class, AuthenticateDevice::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Never echo secrets from request bodies into exception reports.
        $exceptions->dontFlash(['password', 'password_confirmation', 'current_password', 'token', 'smtp_password', ...Credentials::secretInputs()]);

        $exceptions->shouldRenderJsonWhen(fn (Request $request) => $request->is('api/*') || $request->expectsJson());
    })->create();
