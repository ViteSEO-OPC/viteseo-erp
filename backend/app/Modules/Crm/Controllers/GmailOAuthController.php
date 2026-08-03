<?php

namespace App\Modules\Crm\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Crm\Exceptions\GmailConsentFailedException;
use App\Modules\Crm\Services\Mail\Google\GmailConsentFlow;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Connect, inspect and disconnect the CRM's shared Gmail mailbox.
 *
 * Three endpoints are authenticated CRM-admin surfaces and one is not, which is the whole reason
 * this controller is worth reading:
 *
 *   show()     GET    /api/v1/crm/settings/integrations/gmail  — safe status metadata
 *   store()    POST   /api/v1/crm/settings/integrations/gmail/authorization — issue a consent URL
 *   destroy()  DELETE /api/v1/crm/settings/integrations/gmail  — revoke at Google, then forget
 *   callback() GET    /auth/google/callback — UNAUTHENTICATED, and it has to be: Google redirects
 *              the browser here with no bearer token. The single-use state nonce is what
 *              authenticates it, and the path matches GOOGLE_REDIRECT_URI exactly.
 *
 * Thin by construction: refusals are GmailConsentFailedExceptions that render themselves as JSON,
 * so the three API methods have no error branching. callback() is the exception — it catches,
 * because a browser navigation deserves a page, not a JSON error blob.
 */
class GmailOAuthController extends Controller
{
    /** Where the SPA renders this integration. A frontend-routing fact, so it lives in code beside
     *  the redirect that uses it rather than in config next to the origin. */
    private const RETURN_PATH = '/zerem/settings/integrations';

    public function __construct(private readonly GmailConsentFlow $consent) {}

    /** Safe metadata only — mailbox, provider, connected, status. Never a token or an expiry. */
    public function show(): JsonResponse
    {
        return response()->json(['data' => $this->consent->status()]);
    }

    /** Issue a consent URL. Nothing is connected until the user comes back through callback(). */
    public function store(Request $request): JsonResponse
    {
        return response()->json([
            'data' => $this->consent->authorizationUrl($request->user()),
        ]);
    }

    /** Disconnect: revoke at Google where possible, always drop the local grant. Idempotent. */
    public function destroy(): JsonResponse
    {
        return response()->json(['data' => $this->consent->disconnect()]);
    }

    /**
     * Google's redirect target — the one public endpoint, reached as a top-level browser navigation.
     *
     * Answers with a REDIRECT back to Settings → Integrations carrying a short, non-secret status,
     * so the admin lands on the page they started from instead of staring at JSON. The destination
     * is assembled from a fixed configured origin plus a constant path: nothing about it comes from
     * the request, because a callback that honoured a redirect target from its own query string
     * would be an open redirect.
     *
     * Only `gmail=connected` or `gmail=error&reason=<code>` ever appears in that URL. Reason codes
     * are the exception's own vocabulary — never a token, an authorization code, a client secret or
     * a Google response body, all of which would otherwise land in browser history and server logs.
     */
    public function callback(Request $request): JsonResponse|RedirectResponse
    {
        try {
            $mailbox = $this->consent->complete(
                (string) $request->query('state', ''),
                (string) $request->query('code', ''),
                (string) $request->query('error', ''),
            );
        } catch (GmailConsentFailedException $e) {
            return $this->returnToSpa(['gmail' => 'error', 'reason' => $e->reason])
                // No configured SPA origin: fall back to the exception's own JSON rather than
                // guessing a host to send someone's browser to.
                ?? $e->render($request);
        }

        return $this->returnToSpa(['gmail' => 'connected'])
            ?? response()->json(['data' => ['status' => 'connected', 'mailbox' => $mailbox]]);
    }

    /**
     * Redirect to the SPA's integration page, or null when no origin is configured.
     *
     * @param  array<string, string>  $query
     */
    private function returnToSpa(array $query): ?RedirectResponse
    {
        $origin = rtrim((string) config('outreach.frontend_url', ''), '/');

        if ($origin === '') {
            return null;
        }

        return redirect()->away($origin.self::RETURN_PATH.'?'.http_build_query($query));
    }
}
