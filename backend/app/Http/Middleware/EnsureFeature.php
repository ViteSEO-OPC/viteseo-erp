<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\CurrentOrg;
use App\Support\Entitlements;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gates a route behind an availed feature. Applied as `feature:<key>` and MUST
 * run after `set-current-org` (which pins the org). If the current org hasn't
 * availed the feature, the request is rejected with 403 — enforcing
 * "if you didn't avail it, you can't use it" at the API boundary.
 */
class EnsureFeature
{
    public function handle(Request $request, Closure $next, string $feature): Response
    {
        $orgId = CurrentOrg::id();

        if ($orgId === null || ! Entitlements::has($orgId, $feature)) {
            abort(403, "Your organization hasn't availed this feature ({$feature}).");
        }

        return $next($request);
    }
}
