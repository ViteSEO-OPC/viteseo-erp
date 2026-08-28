<?php

namespace App\Modules\Crm\Http\Middleware;

use App\Modules\Crm\Services\CrmAccessService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Blocks a CRM-suspended user from every Zerem endpoint.
 *
 * WHY PER-REQUEST AND NOT TOKEN REVOCATION. The platform issues ONE Sanctum token per login, with no
 * abilities, shared by TeamOra (HRIS), Zerem (CRM) and Sprintly (Projects). Revoking it to end a
 * Zerem session would sign the person out of two products this decision has nothing to do with.
 * Checking on each request achieves the same user-visible outcome — access ends on the very next
 * call, including after a refresh — with a blast radius of exactly one route group.
 *
 * WHY 403 AND NOT 401, which is the single most load-bearing detail here. The CRM axios client
 * (`frontend/src/modules/crm/lib/axios.ts`) treats 401 as "your session is gone": it clears the
 * stored token and redirects to /login. That token is the SHARED one, so answering 401 would destroy
 * the user's TeamOra and Sprintly sessions as a side effect of a CRM-only suspension — precisely the
 * cross-product damage this whole design exists to prevent. A suspended user IS authenticated; they
 * are not authorized for Zerem, and 403 is what says that.
 *
 * `crm_access_suspended` is returned so the frontend can render a blocked state rather than a generic
 * error, without string-matching a message.
 */
class EnsureCrmAccessIsActive
{
    /**
     * Endpoints that stay reachable while suspended.
     *
     * `GET /crm/me` ONLY, so the app can learn its own state and render a "your Zerem access has been
     * suspended" screen instead of a wall of failed requests. Deliberately not `PATCH /crm/me`: a
     * suspended user may read who they are, never write. Kept as a method+path pair rather than a
     * path prefix so the write verb cannot slip through with the read.
     *
     * @var list<array{0: string, 1: string}>
     */
    private const EXEMPT = [
        ['GET', 'api/v1/crm/me'],
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null || $this->isExempt($request)) {
            return $next($request);
        }

        if (app(CrmAccessService::class)->isSuspended($user)) {
            return response()->json([
                'message' => 'Your Zerem access has been suspended. Your other Vite SEO Systems access is unaffected.',
                'code' => 'crm_access_suspended',
            ], 403);
        }

        return $next($request);
    }

    private function isExempt(Request $request): bool
    {
        foreach (self::EXEMPT as [$method, $path]) {
            if ($request->method() === $method && $request->is($path)) {
                return true;
            }
        }

        return false;
    }
}
