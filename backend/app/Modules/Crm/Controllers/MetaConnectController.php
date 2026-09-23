<?php

declare(strict_types=1);

namespace App\Modules\Crm\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Crm\Services\Meta\Connect\MetaConnectException;
use App\Modules\Crm\Services\Meta\Connect\MetaConnectFlow;
use App\Support\CurrentOrg;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Connect, inspect and disconnect an organization's Meta integration.
 *
 * THREE AUTHENTICATED ENDPOINTS AND ONE THAT CANNOT BE. The first three sit behind
 * `role:super_admin|crm_admin` in api.php, matching how this codebase already gates Gmail and
 * Nerby; `callback()` is reached as a top-level browser navigation from Meta with no bearer token,
 * and is authenticated by the single-use state nonce instead.
 *
 * THE ORG IS READ FROM `CurrentOrg`, NOT FROM THE REQUEST. On the three authenticated endpoints
 * SetCurrentOrg has already pinned it from the authenticated user's membership. There is no
 * parameter here a caller could use to name a different tenant, which is the property the whole
 * flow rests on.
 *
 * NOTHING THIS CONTROLLER RETURNS CONTAINS A SECRET — no token, no app secret, no state nonce, and
 * no expiry that would hint at one.
 */
class MetaConnectController extends Controller
{
    /** Where the SPA renders this integration. A frontend-routing fact, so it sits beside the
     *  redirect that uses it rather than in config next to the origin. */
    private const RETURN_PATH = '/zerem/settings/integrations';

    public function __construct(private readonly MetaConnectFlow $connect) {}

    /** Safe status only: whether the server is configured, and whether this org has a live grant. */
    public function show(): JsonResponse
    {
        return response()->json(['data' => $this->connect->status((int) CurrentOrg::id())]);
    }

    /**
     * Issue a consent URL.
     *
     * Nothing is connected by calling this, and no `crm_provider_connections` row is created — a
     * row that existed because someone pressed a button would claim an authorization that has not
     * happened.
     */
    public function store(Request $request): JsonResponse
    {
        try {
            return response()->json([
                'data' => $this->connect->authorizationUrl($request->user(), (int) CurrentOrg::id()),
            ]);
        } catch (MetaConnectException $e) {
            return response()->json(
                ['message' => $e->getMessage(), 'reason' => $e->failure->value],
                // Not configured is a server state, not a client mistake.
                $e->failure->value === 'not_configured' ? 503 : 422,
            );
        }
    }

    /** Disconnect every live Meta connection for this org. Deletes nothing. Idempotent. */
    public function destroy(): JsonResponse
    {
        return response()->json([
            'data' => ['disconnected' => $this->connect->disconnect((int) CurrentOrg::id())],
        ]);
    }

    /**
     * Meta's redirect target — the one public endpoint of this flow.
     *
     * Answers with a REDIRECT back to Settings → Integrations carrying a short, non-secret status,
     * so the admin lands on the page they started from rather than on a JSON blob. The destination
     * is a configured origin plus a constant path: nothing in it comes from the request, because a
     * callback that honoured a redirect target from its own query string would be an open redirect.
     *
     * Only `meta=pending` or `meta=error&reason=<code>` ever appears in that URL. Reason codes are
     * the failure enum's own vocabulary — never a token, an authorization code or a Meta response
     * body, all of which would otherwise land in browser history and access logs.
     *
     * `pending`, not `connected`: reaching this point means the redirect was valid, not that a
     * grant exists. In Phase 3F.3A it never gets that far.
     */
    public function callback(Request $request): RedirectResponse|Response
    {
        try {
            $this->connect->complete(
                (string) $request->query('state', ''),
                (string) $request->query('code', ''),
                $request->query('error') === null ? null : (string) $request->query('error'),
                $request->query('error_reason') === null ? null : (string) $request->query('error_reason'),
            );

            $url = $this->returnUrl(['meta' => 'pending']);
        } catch (MetaConnectException $e) {
            $url = $this->returnUrl(['meta' => 'error', 'reason' => $e->failure->value]);
        }

        // With no SPA origin configured there is nowhere safe to send the browser, and a redirect
        // target from the request is the one thing this endpoint must never honour.
        return $url === null
            ? response()->noContent()
            : redirect()->away($url);
    }

    /**
     * The SPA's integrations page, or null when no origin is configured.
     *
     * Reuses `outreach.frontend_url` — the key the Gmail callback already redirects through —
     * rather than adding a second answer to "where does the SPA live".
     *
     * @param  array<string, string>  $query
     */
    private function returnUrl(array $query): ?string
    {
        $origin = rtrim((string) config('outreach.frontend_url', ''), '/');

        if ($origin === '') {
            return null;
        }

        return $origin.self::RETURN_PATH.'?'.http_build_query($query);
    }
}
