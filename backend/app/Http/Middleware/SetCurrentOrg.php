<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\User;
use App\Support\CurrentOrg;
use App\Support\ServiceTokens;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Pins the tenant org for the request from the authenticated user, so the
 * BelongsToOrg global scope resolves one consistent org per request without
 * re-querying membership on every scoped query.
 *
 * MUST run AFTER authentication (apply it right after `auth:sanctum`). This is
 * also the future hook point for the org switcher — read the chosen org from a
 * header/session here and validate it against the user's memberships.
 */
class SetCurrentOrg
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null || ! method_exists($user, 'currentOrgId')) {
            return $next($request);
        }

        /*
         * A SERVICE TOKEN ACTS FOR THE ORG IT WAS ISSUED FOR, and for no other.
         *
         * The service user belongs to no org by design (see ServiceTokens), so without this it
         * would resolve to nothing and every tenant-scoped callback would fail closed -- which is
         * the safe failure. The token's own `org:<id>` binding is what makes it work, and it is
         * honoured ONLY for a registered service role presenting a real personal access token: a
         * human's token can never choose its tenant. A service user whose token carries no valid
         * binding is refused rather than allowed to fall through to any other resolution.
         */
        if ($user instanceof User && ServiceTokens::token($user) !== null && ServiceTokens::isServiceUser($user)) {
            $bound = ServiceTokens::boundOrg($user);
            abort_if($bound === null, 403, 'This service token is not bound to an organization.');
            CurrentOrg::set($bound);

            return $next($request);
        }

        $orgId = $user->currentOrgId();

        /*
         * PIN ONLY WHEN THERE IS SOMETHING TO PIN.
         *
         * `set(null)` is not "no opinion" -- it is an OVERRIDE, and CurrentOrg::id()
         * reads the override before anything else. So pinning a null answer here
         * silences the resolution that follows it, including the one deliberate
         * exception: a super_admin with NO membership resolves to the provider org,
         * which is what every admin surface -- billing, onboarding, the org list --
         * runs on.
         *
         * That is exactly what broke when this middleware went onto the HR/CRM/
         * Projects routes: the platform's own administrator was pinned to nothing and
         * then told "your organization hasn't availed this feature", by a gate whose
         * own fallback would have answered correctly if it had ever been reached.
         *
         * Leaving it unset is not a hole. `id()` still fails closed for an ordinary
         * user with no membership -- it returns null there on its own.
         */
        if ($orgId !== null) {
            CurrentOrg::set($orgId);
        }

        return $next($request);
    }
}
