<?php

declare(strict_types=1);

namespace App\Modules\Crm\Services\Calling;

use App\Models\User;
use App\Modules\Crm\Models\Call;
use App\Modules\Crm\Services\Calling\Contracts\CallProvider;
use App\Support\CurrentOrg;
use Twilio\Jwt\AccessToken;
use Twilio\Jwt\Grants\VoiceGrant;

/**
 * Twilio Voice as the CRM's first real call transport (outbound only).
 *
 * ── One centralized ViteSEO account, server-side ──
 * Credentials come from {@see TwilioConfig}: Zerem's ONE centralized ViteSEO Twilio account, the
 * server-side TWILIO_* configuration, shared by every authorized CRM user — intentionally. Nothing a
 * request carries can substitute other credentials. (Per-organization accounts exist in the code but are
 * off unless a deployment explicitly switches them on; see TwilioConfig.)
 *
 * ── What the browser gets, and what it never gets ──
 * Exactly one credential crosses to the client: an Access Token, signed here with the API key pair,
 * scoped to a single TwiML Application, and valid for minutes. The account's Auth Token — the master
 * secret Twilio also signs webhooks with — is used ONLY for signature validation on the way back in
 * and is never serialized, logged, or returned by any endpoint. Neither is the API secret.
 *
 * ── Identity ──
 * The token's identity is `crm-user-{id}`. It is namespaced because a Twilio account may be shared
 * with other products, and it is derived from the authenticated user rather than accepted from the
 * client. Nothing is authorized by identity: it labels the Twilio-side registration, while the CRM's
 * own authorization already happened before this method was reached.
 *
 * ── Incoming is deliberately off ──
 * `VoiceGrant::setIncomingAllow()` is never enabled. This MVP is outbound-only, and an unset incoming
 * grant means a token cannot receive a call even if Twilio were configured to route one.
 */
class TwilioCallProvider implements CallProvider
{
    public const NAME = 'twilio';

    public function name(): string
    {
        return self::NAME;
    }

    /**
     * Usable RIGHT NOW: the configuration in force (the centralized account) enabled, complete and
     * verified. A partial configuration is treated as no configuration — a missing caller ID or
     * webhook URL fails at the carrier, minutes into a BD's workflow, which is a far worse failure than
     * an honest "not configured" before the button is ever shown.
     */
    public function isConfigured(): bool
    {
        return $this->config()->isUsable();
    }

    /**
     * Whether this organization switched this transport on in its OWN stored settings. Only possible
     * when per-organization accounts are explicitly switched on (see TwilioConfig); in Zerem's default
     * centralized configuration this is always false and CALL_PROVIDER selects the transport instead.
     */
    public function isSelectedByOrganization(): bool
    {
        $config = $this->config();

        return $config->source === TwilioConfig::SOURCE_ORGANIZATION && $config->enabled;
    }

    /** The reason this provider exists: it is the browser-calling transport. */
    public function supportsBrowserCalling(): bool
    {
        return true;
    }

    /**
     * @return array{token: string, identity: string, correlation: string, expiresIn: int}
     *
     * @throws BrowserCallingUnavailable when the call's org can no longer carry a browser call (D5)
     */
    public function issueBrowserToken(User $user, Call $call): array
    {
        // The org whose credentials sign this token is the one that OWNS the call, not merely the one
        // making the request. They are the same org in every reachable path — CallPolicy has already
        // authorized this user against this call — and taking it from the record means a token can
        // never be signed by one tenant's account for another tenant's call.
        $config = TwilioConfig::forOrg((int) $call->org_id);

        // Re-checked here, not trusted from the capability answer: settings can be switched off or
        // emptied between the two. That is a client-facing "not available", never a 500.
        if (! $config->isUsable()) {
            throw new BrowserCallingUnavailable('Twilio is not configured for this organization.');
        }

        $ttl = $config->tokenTtl;
        $identity = 'crm-user-'.$user->id;

        $token = new AccessToken(
            (string) $config->accountSid,
            (string) $config->apiKey,
            (string) $config->apiSecret,
            $ttl,
            $identity,
        );

        $grant = new VoiceGrant;
        $grant->setOutgoingApplicationSid((string) $config->twimlAppSid);
        // Outbound-only: no setIncomingAllow(true). See the class docblock.
        $token->addGrant($grant);

        return [
            'token' => $token->toJWT(),
            'identity' => $identity,
            // Minted here, on the authenticated path, so the browser never learns the call id and
            // cannot substitute another one. See CallCorrelation.
            'correlation' => CallCorrelation::issue($call),
            'expiresIn' => $ttl,
        ];
    }

    /**
     * Resolved per call rather than cached: a settings change must take effect on the next request,
     * with no cache for an admin to wonder about. It is one indexed single-row lookup.
     */
    private function config(): TwilioConfig
    {
        return TwilioConfig::forOrg(CurrentOrg::id());
    }
}
