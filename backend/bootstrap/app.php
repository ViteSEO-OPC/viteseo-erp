<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    // Registers the /broadcasting/auth endpoint (guarded by Sanctum) for private
    // Reverb channels. Channels themselves are defined per-module (see
    // routes/channels.php). Realtime stays inert until a broadcaster is configured
    // (BROADCAST_CONNECTION), so this is safe even when Reverb is off.
    ->withBroadcasting(
        __DIR__.'/../routes/channels.php',
        ['middleware' => ['auth:sanctum']],
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Spatie permission middleware aliases — used to gate HR-only routes
        // with role:hr_officer / permission:hr.<resource>.<action>.
        $middleware->alias([
            'role' => \Spatie\Permission\Middleware\RoleMiddleware::class,
            'permission' => \Spatie\Permission\Middleware\PermissionMiddleware::class,
            'role_or_permission' => \Spatie\Permission\Middleware\RoleOrPermissionMiddleware::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Render JSON (not the HTML "Route [login] not defined" page) for API calls
        // AND the broadcasting auth endpoint, so an unauthenticated private-channel
        // handshake returns a clean 401 instead of a 500.
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->is('broadcasting/auth'),
        );
    })->create();
