<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\Connections;
use App\Support\CurrentOrg;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gates a cross-module bridge route behind an ENABLED connection
 * (connection:<key>). Apply after set-current-org so CurrentOrg is pinned. The
 * orchestrator wires this onto the actual bridge/posting routes later.
 */
class EnsureConnectionEnabled
{
    public function handle(Request $request, Closure $next, string $key): Response
    {
        abort_unless(
            Connections::enabled(CurrentOrg::id() ?? 0, $key),
            403,
            'This integration is turned off.',
        );

        return $next($request);
    }
}
