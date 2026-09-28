<?php

namespace App\Modules\Crm\Http\Middleware;

use App\Models\User;
use App\Modules\Crm\Services\Discovery\NerbyServiceIdentity;
use App\Support\ServiceTokens;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Machine-only: the endpoint accepts the NERBY discovery service and nobody else.
 *
 * For the scraper's WRITE callbacks — discovered-business ingest and the Website Intelligence
 * findings. They used to be gated only on `crm.leads.import`, which every Business Developer holds
 * for spreadsheet imports, so any BD could post invented prospects (fake place ids, coordinates,
 * phones), forge audit findings and candidate verifications, and rewrite a lead's website. A human
 * has no reason to call these: no screen does. Human imports keep their own, unchanged routes.
 *
 * Requires the service role AND a valid org-bound service token — a session, a wildcard token or an
 * unbound token is refused.
 */
class EnsureNerbyServiceCaller
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        $isService = $user instanceof User
            && $user->hasRole(NerbyServiceIdentity::ROLE)
            && ServiceTokens::boundOrg($user) !== null;

        abort_unless($isService, 403, 'This endpoint accepts the discovery service only.');

        return $next($request);
    }
}
