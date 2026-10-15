<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->trustProxies(at: '*');

        // Provider callbacks are authenticated with a shared secret inside the
        // controller, not with a browser CSRF token (they are server-to-server).
        $middleware->validateCsrfTokens(except: [
            'api/v1/webhooks/*',
        ]);

        $middleware->redirectTo(
            guests: '/login',
            users: '/dashboard',
        );
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();
