<?php

use App\Http\Middleware\EnsureRole;
use App\Http\Middleware\EnsureAccountIsActive;
use App\Http\Middleware\ForceHttps;
use App\Support\SafeRedirectTarget;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->trustProxies(at: array_values(array_filter(array_map('trim', explode(',', (string) env('TRUSTED_PROXIES', ''))))));
        $middleware->web(append: [EnsureAccountIsActive::class, ForceHttps::class]);

        $middleware->alias([
            'role' => EnsureRole::class,
        ]);

        $middleware->redirectGuestsTo(function (Request $request) {
            $redirect = SafeRedirectTarget::resolve($request->fullUrl(), $request, '');

            return route('login', $redirect !== '' ? ['redirect' => $redirect] : []);
        });
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();
