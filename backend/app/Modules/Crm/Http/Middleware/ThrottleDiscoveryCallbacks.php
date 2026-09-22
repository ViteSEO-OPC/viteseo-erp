<?php

namespace App\Modules\Crm\Http\Middleware;

use App\Models\User;
use App\Support\ServiceTokens;
use Closure;
use Illuminate\Routing\Middleware\ThrottleRequests;

/**
 * The discovery endpoints' existing throttle, with the service identity counted PER RUN TOKEN.
 *
 * WHY. Laravel's plain `throttle:N,1` keys its counter on the authenticated user. Every discovery run,
 * for every tenant, now calls back as the ONE NERBY service account — so all of them shared a single
 * 30-per-minute counter, one tenant's nationwide sweep starved every other run, and the refusals
 * surfaced as "nothing found" (H-4). Each run token now has its own counter at the SAME per-route
 * limit: the rate a single run may spend is unchanged; runs simply stop spending each other's.
 *
 * HUMANS ARE UNTOUCHED: any caller that is not a service token is handed straight to the parent
 * middleware with the route's own arguments, so its key, limit and window are exactly what
 * `throttle:N,1` produced before.
 */
class ThrottleDiscoveryCallbacks extends ThrottleRequests
{
    public function handle($request, Closure $next, $maxAttempts = 60, $decayMinutes = 1, $prefix = '')
    {
        $user = $request->user();

        if ($user instanceof User
            && ($token = ServiceTokens::token($user)) !== null
            && ServiceTokens::isServiceUser($user)) {
            return $this->handleRequest($request, $next, [(object) [
                'key' => $prefix.'nerby-service-token:'.$token->getKey(),
                'maxAttempts' => $this->resolveMaxAttempts($request, $maxAttempts),
                'decaySeconds' => 60 * (int) $decayMinutes,
                'afterCallback' => null,
                'responseCallback' => null,
            ]]);
        }

        return parent::handle($request, $next, $maxAttempts, $decayMinutes, $prefix);
    }
}
