<?php

declare(strict_types=1);

namespace App\Modules\Crm\Http\Middleware;

use App\Modules\Crm\Models\Call;
use App\Modules\Crm\Services\Calling\CallCorrelation;
use App\Modules\Crm\Services\Calling\TwilioCallProvider;
use App\Modules\Crm\Services\Calling\TwilioConfig;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Twilio\Security\RequestValidator;

/**
 * Proves an incoming webhook really came from Twilio — from the Twilio account Zerem's calls are
 * placed on.
 *
 * ── Why this middleware exists at all ──
 * Every other CRM route sits behind five gates (`auth:sanctum`, `set-current-org`,
 * `subscription-active`, `feature:crm`, `EnsureCrmAccessIsActive`), and `Routes/api.php` records a
 * deliberate decision that the CRM exposes no unauthenticated endpoint. Twilio cannot satisfy any of
 * those gates: it is a third party calling us from the internet with no user, no token and no tenant.
 *
 * This is the ONE documented exception, and this middleware is what makes it safe. It replaces
 * "authenticated user" with "provably Twilio" — nothing else about the request is trusted, and in
 * particular no identifier in the body is dereferenced as an identity.
 *
 * ── Validation is the official SDK's, not ours ──
 * {@see RequestValidator} is Twilio's own implementation of their signing scheme: it rebuilds the
 * canonical string from the full URL plus every POST parameter, HMAC-SHA1s it with the account's Auth
 * Token, and compares in constant time. No signature cryptography is written by hand here — this
 * class only assembles the inputs and decides what to do with a false.
 *
 * ── WHICH Auth Token ──
 * Zerem places every browser call through ONE centralized ViteSEO Twilio account (see TwilioConfig), so
 * every webhook is verified against that account's Auth Token — the same account the call was placed
 * on. The org is still read from the correlation and passed to {@see TwilioConfig::forOrg()}, so that a
 * deployment which explicitly switches on per-organization accounts verifies each org's webhooks
 * against that org's own account. With that option off — the default — the answer is always the
 * centralized account, and a per-organization row can never become an alternative signer.
 *
 * The org is read the only way it safely can be: from the CORRELATION TOKEN — in the body of the voice
 * webhook, in the action URL's query string for the completion webhook — an HMAC-signed value this
 * server minted for one specific `crm_calls` row. NO ORG ID FROM THE REQUEST IS EVER READ; only our own
 * signed token is, and it commits to a call id that cannot be edited without breaking the HMAC.
 * Resolving it before the Twilio signature check is safe because it is a read-only lookup that
 * authorizes nothing on its own — the request still has to survive the signature check below, and the
 * controller independently re-resolves the same correlation before touching anything.
 *
 * When the correlation names no call (a forged or absent token), the centralized account is used: a
 * genuinely-Twilio request carrying a junk correlation is still recognised as Twilio's, and the
 * controller answers it by doing nothing. An EXPIRED token still names its call — expiry is the
 * controller's question, not this one — so a late callback is verified against the right account and
 * then concluded as a no-op.
 *
 * ── The URL must match byte-for-byte ──
 * Twilio signed the URL IT called, which is the public one configured on the TwiML App. Behind a
 * proxy or a tunnel, `$request->fullUrl()` is the internal address and would never match, so the URL
 * is rebuilt from the resolved configuration's `webhook_base_url` — the same value Twilio was given. A
 * mismatch here is the single most common cause of a valid webhook being rejected.
 *
 * ── The body must match byte-for-byte too ──
 * See {@see signedParams()}: the signature is checked against the raw form body, not the input
 * Laravel's global middleware has already trimmed.
 */
class ValidateTwilioSignature
{
    /** Twilio's signature header. Present on every signed request they make. */
    public const HEADER = 'X-Twilio-Signature';

    public function handle(Request $request, Closure $next): Response
    {
        $config = $this->configFor($request);

        // Unconfigured means unverifiable. Refusing (rather than passing through) is the safe
        // direction: a deployment with no Twilio credentials has no business accepting call webhooks
        // at all, and every deployment running the free manual mode is in exactly that state.
        if ($config->authToken === null || $config->webhookBaseUrl === null) {
            return response('Twilio calling is not configured.', Response::HTTP_SERVICE_UNAVAILABLE);
        }

        $signature = (string) $request->header(self::HEADER, '');

        if ($signature === '') {
            return response('Missing Twilio signature.', Response::HTTP_FORBIDDEN);
        }

        $validator = new RequestValidator($config->authToken);

        // The form body exactly as Twilio sent it — see signedParams(). Query parameters are NOT added
        // to it: they are already part of the URL Twilio signed, and repeating them would produce a
        // canonical string Twilio never signed.
        if (! $validator->validate($signature, $this->publicUrl($request, $config->webhookBaseUrl), $this->signedParams($request))) {
            return response('Invalid Twilio signature.', Response::HTTP_FORBIDDEN);
        }

        return $next($request);
    }

    /**
     * The credentials this request must be checked against: the configuration in force for the
     * correlated call's organization (the centralized account, unless per-org accounts are switched
     * on), and the centralized account when the correlation names no call.
     *
     * The lookup is deliberately minimal — one column, by primary key, restricted to calls actually
     * carried by this provider. It answers "whose account should have signed this?" and nothing else;
     * the controller does its own, stricter resolution before any state changes.
     */
    private function configFor(Request $request): TwilioConfig
    {
        // Either purpose: the voice webhook carries a DIAL token in its body, the completion webhook a
        // COMPLETE token in its action URL's query string. Expiry is deliberately NOT enforced here —
        // this only decides whose Auth Token Twilio signed with, and a late callback must still be
        // checked against the account that placed the call. The controller re-resolves the same token
        // with expiry before changing anything.
        $token = $request->input(CallCorrelation::PARAM);
        $callId = CallCorrelation::resolveCallId($token, CallCorrelation::PURPOSE_DIAL, enforceExpiry: false)
            ?? CallCorrelation::resolveCallId($token, CallCorrelation::PURPOSE_COMPLETE, enforceExpiry: false);

        if ($callId === null) {
            return TwilioConfig::fromDeployment();
        }

        // Unscoped for the same reason the controller's lookup is: there is no authenticated user, so
        // the tenant scope would resolve to the first org and silently hide every other org's calls.
        // The correlation token has already named exactly one row; the org is READ from it.
        $orgId = Call::query()
            ->withoutGlobalScope('org')
            ->whereKey($callId)
            ->where('provider', TwilioCallProvider::NAME)
            ->value('org_id');

        return $orgId === null
            ? TwilioConfig::fromDeployment()
            : TwilioConfig::forOrg((int) $orgId);
    }

    /**
     * The POST parameters exactly as Twilio signed them.
     *
     * By the time route middleware runs, Laravel's GLOBAL TrimStrings and ConvertEmptyStringsToNull
     * have already rewritten the request's parsed input. A value Twilio sent with surrounding
     * whitespace — a caller name, a forwarded-from label — would no longer match the canonical string
     * Twilio signed, and a genuine webhook would be refused. So the form body is re-parsed from the
     * RAW content, which those middleware never touch. `parse_str` is the same decoder PHP used to
     * build `$_POST` in the first place, so this is the untouched original, not a reinterpretation.
     * Trimming stays on globally and for every other route; only this signature check reads around it.
     *
     * Only a form-encoded body is re-parsed, which is all Twilio sends. Anything else falls back to the
     * parsed input — still checked against the signature, so the fallback can only ever cause a false
     * rejection, never a false acceptance.
     *
     * @return array<string, mixed>
     */
    private function signedParams(Request $request): array
    {
        $raw = $request->getContent();
        $type = strtolower((string) $request->header('Content-Type', ''));

        if ($raw !== '' && str_starts_with($type, 'application/x-www-form-urlencoded')) {
            parse_str($raw, $params);

            return $params;
        }

        return $request->post();
    }

    /**
     * The URL as Twilio saw it: the configured public origin plus this request's path and query.
     * Trailing slashes on the configured origin are normalized away so a copy-pasted value with one
     * does not silently break every signature.
     */
    private function publicUrl(Request $request, string $baseUrl): string
    {
        $path = '/'.ltrim($request->getPathInfo(), '/');
        $query = $request->getQueryString();

        return rtrim($baseUrl, '/').$path.($query !== null && $query !== '' ? '?'.$query : '');
    }
}
