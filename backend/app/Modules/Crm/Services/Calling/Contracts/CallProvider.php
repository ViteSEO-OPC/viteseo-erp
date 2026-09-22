<?php

declare(strict_types=1);

namespace App\Modules\Crm\Services\Calling\Contracts;

use App\Models\User;
use App\Modules\Crm\Models\Call;

/**
 * The seam between the CRM's call domain and whatever actually carries the audio.
 *
 * The `crm_calls` migration reserved `provider` and `external_call_id` for exactly this, naming
 * "Twilio / Teams / Zoom / RingCentral / Aircall later, behind a CallProvider contract". This is that
 * contract.
 *
 * ── The CRM works with NO paid provider ──
 * {@see \App\Modules\Crm\Services\Calling\ManualCallProvider} is the default and needs no account, no
 * credentials and no subscription: the BD dials on a physical phone and logs the result, which is the
 * workflow this CRM has always had. A paid provider such as Twilio is an OPTIONAL capability layered
 * on top. Nothing in the call domain may require one.
 *
 * That is why capability is a QUESTION on this interface rather than an assumption:
 * {@see supportsBrowserCalling} tells the UI whether to offer "Call from Browser" at all, and a
 * provider that answers false is never asked for a token.
 *
 * Deliberately NOT here: dialing. Under a browser provider the browser places the call itself over
 * WebRTC; the backend never originates one. Under the manual provider there is nothing to place.
 *
 * CallSessionService remains the CRM orchestration layer and knows nothing about any of this — a
 * provider supplies transport, never workflow.
 */
interface CallProvider
{
    /**
     * The value stored in `crm_calls.provider` for calls this provider carries. Rows written before
     * any provider existed keep `manual`, which is also what the manual provider writes — so history
     * and present agree without a backfill.
     */
    public function name(): string;

    /**
     * Whether this provider is usable right now. The manual provider is ALWAYS configured; a paid one
     * answers false until its credentials are present, and false means the CRM quietly offers manual
     * calling instead. It never throws and never half-starts a call.
     */
    public function isConfigured(): bool;

    /**
     * Whether the CURRENT organization has explicitly chosen and switched on this transport in
     * Settings → Integrations → Calling, as opposed to inheriting whatever the deployment configured.
     *
     * This is what makes the paid transport a CLIENT decision instead of a server-file edit: an org
     * that configures its own account and enables it gets browser calling on the next request, with
     * no `CALL_PROVIDER` change and no deploy. False for the manual provider, which nobody selects —
     * it is what runs when nothing else can.
     */
    public function isSelectedByOrganization(): bool;

    /**
     * Whether this provider can place a call from inside the browser. False for manual — the BD's
     * phone is the transport — and the UI must not render a browser-call control when it is false.
     */
    public function supportsBrowserCalling(): bool;

    /**
     * A short-lived credential letting THIS user's browser place THIS call, plus the correlation token
     * the provider hands back to our webhook.
     *
     * Only ever called when {@see supportsBrowserCalling} and {@see isConfigured} are both true.
     *
     * @return array{token: string, identity: string, correlation: string, expiresIn: int}
     *
     * @throws \RuntimeException when the provider cannot place browser calls.
     */
    public function issueBrowserToken(User $user, Call $call): array;
}
