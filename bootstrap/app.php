<?php

use App\Http\Middleware\DisableWebViewCache;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // NativePHP runs a local server on the device — cross-site requests are
        // impossible, so CSRF protection serves no purpose and breaks Livewire
        // when the WebView reuses a cached page from the previous app launch.
        $middleware->remove(PreventRequestForgery::class);

        // Prevent Chrome WebView from saving page state across app restarts.
        // Without this, WebView replays cached POST navigation on resume and
        // shows its own "This page has expired" confirmation dialog.
        $middleware->append(DisableWebViewCache::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
