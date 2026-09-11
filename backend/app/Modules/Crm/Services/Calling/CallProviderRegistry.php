<?php

declare(strict_types=1);

namespace App\Modules\Crm\Services\Calling;

use App\Modules\Crm\Services\Calling\Contracts\CallProvider;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Facades\Config;

/**
 * Resolves the call transport Zerem actually has, for the organization making this request.
 *
 * ── Selecting is not the same as having ──
 * Naming a provider states an intent. Whether that intent is honoured depends on whether the
 * credentials are really there, which is why every branch below asks the resolved provider
 * `isConfigured()` before returning it, and falls through to {@see ManualCallProvider} when the answer
 * is no.
 *
 * The consequence is the property the CRM is required to have: a missing or partial Twilio
 * configuration, a rotated-away API secret or a typo'd provider name keeps Zerem working. It loses
 * browser calling — a capability — and never loses calling. There is no configuration state in which
 * the Call panel is broken rather than merely manual.
 *
 * ── How a paid transport is selected ──
 * 1. THE CENTRALIZED VITESEO ACCOUNT — Zerem's intended configuration. `CALL_PROVIDER=twilio` plus the
 *    complete server-side TWILIO_* values give every authorized CRM user browser calling through that
 *    one account and caller ID (see TwilioConfig). Leaving `CALL_PROVIDER` at `manual`, or leaving any
 *    TWILIO_* value empty, keeps everyone on the free transport.
 * 2. AN ORGANIZATION'S OWN ACCOUNT — only when a deployment explicitly switches on per-organization
 *    accounts (`calling.organization_credentials`). Off by default, which makes the first loop below
 *    inert: `isSelectedByOrganization()` can only be true for an org's own stored row, and
 *    TwilioConfig does not consult stored rows while that support is off.
 *
 * NOTHING HERE READS A CLIENT REQUEST. Which transport a call uses is decided from server-side
 * configuration only, so a browser that asks for `provider=twilio` in a manual deployment simply gets a
 * manual call, and no request can bring credentials of its own.
 *
 * Resolution is per-request and cheap, so a configuration change takes effect immediately with no
 * cache to clear.
 */
class CallProviderRegistry
{
    public function __construct(private readonly Container $container) {}

    /**
     * The provider to actually use: an org's own switched-on account (per-org support only), else the
     * centralized account when it is selected and usable, else manual. This is what every caller
     * should use; nothing outside this class should read `calling.provider`.
     */
    public function active(): CallProvider
    {
        // Per-organization accounts — inert unless explicitly switched on (see the class docblock).
        foreach ($this->paidProviders() as $provider) {
            if ($provider->isSelectedByOrganization() && $provider->isConfigured()) {
                return $provider;
            }
        }

        // The centralized ViteSEO account, when CALL_PROVIDER names it and it is genuinely usable.
        $named = $this->resolveConfigured();

        if ($named !== null && $named->isConfigured()) {
            return $named;
        }

        return $this->manual();
    }

    /**
     * Whether browser calling is available right now — the single question the UI asks. False means
     * the Call panel offers "Call on Phone" and logging only, and never renders a browser-call button
     * it cannot honour.
     */
    public function supportsBrowserCalling(): bool
    {
        $provider = $this->active();

        return $provider->isConfigured() && $provider->supportsBrowserCalling();
    }

    /** The free transport, always available. */
    public function manual(): ManualCallProvider
    {
        return $this->container->make(ManualCallProvider::class);
    }

    /**
     * Every registered transport except the free one, in configuration order.
     *
     * Provider-neutral on purpose: a second paid transport (Teams, RingCentral, Aircall — all named in
     * the `crm_calls` migration) becomes available through one line in `config/calling.php`, with
     * nothing here to change.
     *
     * @return list<CallProvider>
     */
    private function paidProviders(): array
    {
        $out = [];

        foreach ($this->providerMap() as $name => $class) {
            if ($name === ManualCallProvider::NAME) {
                continue;
            }

            $out[] = $this->container->make($class);
        }

        return $out;
    }

    /**
     * The provider named by configuration, or null when the name is unknown. An unknown name is a
     * deployment typo; treating it as "no provider" keeps the CRM up instead of 500-ing every call.
     */
    private function resolveConfigured(): ?CallProvider
    {
        $name = (string) Config::get('calling.provider', ManualCallProvider::NAME);
        $map = $this->providerMap();

        if (! isset($map[$name])) {
            return null;
        }

        return $this->container->make($map[$name]);
    }

    /** @return array<string, class-string<CallProvider>> */
    private function providerMap(): array
    {
        /** @var array<string, class-string<CallProvider>> $map */
        $map = (array) Config::get('calling.providers', []);

        return $map;
    }
}
