<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\User;
use App\Support\ServiceTokens;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * A service token reaches its registered callback routes and nothing else.
 *
 * Appended to the whole `api` group, not to one module's routes, because the point is what the token
 * must NOT reach: other modules, profile and notification endpoints, anything a role would open by
 * accident later. See {@see ServiceTokens}.
 *
 * Costs nothing for the browser: it acts only when a bearer token is presented, and the SPA
 * authenticates with a session cookie. Machine callers that are not service users (n8n) pass
 * through untouched.
 */
class RestrictServiceTokens
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->bearerToken() === null) {
            return $next($request);
        }

        $user = $request->user('sanctum');
        if (! $user instanceof User || ! ServiceTokens::isServiceUser($user)) {
            return $next($request);
        }

        if (ServiceTokens::boundOrg($user) === null || ! ServiceTokens::allows($user, $request)) {
            abort(403, 'This service token may not call this endpoint.');
        }

        return $next($request);
    }
}
