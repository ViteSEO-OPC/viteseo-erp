<?php

declare(strict_types=1);

namespace App\Modules\Crm\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Crm\Models\CallProviderSetting;
use App\Modules\Crm\Requests\UpdateCallProviderSettingRequest;
use App\Modules\Crm\Resources\CallProviderSettingResource;
use App\Modules\Crm\Services\Calling\CallProviderRegistry;
use App\Modules\Crm\Services\Calling\CallProviderSettingService;
use App\Modules\Crm\Services\Calling\TwilioCallProvider;
use App\Modules\Crm\Services\Calling\TwilioConfig;
use App\Support\CurrentOrg;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Settings → Integrations → Calling — where a client turns browser calling on.
 *
 * Admin / Super Admin only, enforced by `role:super_admin|crm_admin` on the route group. Unlike the
 * Nearby provider settings next to it, a client's own admin IS allowed here, and the difference is
 * deliberate: the Nearby table holds ViteSEO's centrally-bought API keys, while this one holds the
 * client's OWN Twilio account — their credentials, their bill, their decision. The Nearby route
 * comment states the rule exactly: a table a client brings its own key to needs `org_id` and the
 * `crm_admin` gate, which is what {@see CallProviderSetting} and these routes have.
 *
 * CENTRALLY MANAGED ORGANIZATIONS ARE READ-ONLY. When an organization's calling runs on the
 * centralized ViteSEO account — every organization in centralized mode, and the designated central
 * organization when per-organization accounts are on ({@see TwilioConfig::isCentrallyManaged()}) —
 * there is nothing here to configure. Saving, clearing and testing are refused with a 403, and no stored
 * row is ever returned for it: a row saved there would at best be ignored, and at worst be mistaken for
 * the account that organization's calls actually run on.
 *
 * THIN. Every decision lives in {@see CallProviderSettingService} (sole writer, and the only place
 * that can verify a credential without returning it) and every response shape lives in
 * {@see CallProviderSettingResource} (the allow-list that makes leaking a secret impossible). This
 * class routes and nothing more.
 *
 * The free manual transport is not implemented here either. It is the ABSENCE of configuration: with
 * no row the capability resolver answers "manual", so a CRM that has never visited this page behaves
 * exactly as it always has.
 */
class CallSettingsController extends Controller
{
    public function __construct(
        private readonly CallProviderSettingService $settings,
        private readonly CallProviderRegistry $providers,
    ) {}

    /** The org's stored configuration plus what is actually in force, and why. */
    public function show(): JsonResponse
    {
        return $this->overview();
    }

    /** Configure the org's Twilio account. Secrets are write-only; omitting one keeps it. */
    public function update(UpdateCallProviderSettingRequest $request): JsonResponse
    {
        $refusal = $this->refuseWhenCentrallyManaged();
        if ($refusal !== null) {
            return $refusal;
        }

        $this->settings->configure($request->validated(), $request->user());

        return $this->overview();
    }

    /** Remove the org's stored secrets. Idempotent; identifiers and the switch are left alone. */
    public function destroyCredentials(Request $request): JsonResponse
    {
        $refusal = $this->refuseWhenCentrallyManaged();
        if ($refusal !== null) {
            return $refusal;
        }

        $this->settings->clearCredentials($request->user());

        return $this->overview();
    }

    /**
     * Verify the stored configuration against Twilio.
     *
     * Returns a status code, never Twilio's error text, and NEVER PLACES A CALL — a credential check
     * must not cost the client a connected minute or ring a real number. With an incomplete
     * configuration it reports `not_configured` without making a request, because a test that cannot
     * run must say so rather than report a success nothing verified.
     */
    public function test(Request $request): JsonResponse
    {
        $refusal = $this->refuseWhenCentrallyManaged();
        if ($refusal !== null) {
            return $refusal;
        }

        return $this->overview(tested: $this->settings->test($request->user()));
    }

    /** The one answer for "this organization's calling is not configured from here": a 403. */
    private function refuseWhenCentrallyManaged(): ?JsonResponse
    {
        if (! TwilioConfig::isCentrallyManaged(CurrentOrg::id())) {
            return null;
        }

        return response()->json([
            'message' => 'Calling for this organization is managed centrally by ViteSEO and cannot be changed here.',
            'errors' => ['provider' => ['Calling is centrally managed for this organization.']],
        ], 403);
    }

    /**
     * One payload: the stored row, the effective state, and the reason they may differ.
     *
     * The two are reported SEPARATELY and never merged, for the same reason the Nearby settings do it:
     * "what did we save" and "what will actually happen on the next call" can legitimately disagree —
     * a saved-but-switched-off account, a configuration missing one field — and a reader who has to
     * infer the second from the first will eventually infer it wrong.
     */
    private function overview(?CallProviderSetting $tested = null): JsonResponse
    {
        $centrallyManaged = TwilioConfig::isCentrallyManaged(CurrentOrg::id());

        // A centrally managed organization has no configuration of its own to show. An unsaved, empty
        // row keeps the payload's shape — and guarantees that a row stored before the organization was
        // designated central, which nothing reads, is never presented as if it were in force.
        //
        // Otherwise, substitute the in-memory result so a verdict that was deliberately not persisted
        // (a test run with no saved row) still reaches the client.
        $row = $centrallyManaged
            ? new CallProviderSetting(['provider' => TwilioCallProvider::NAME])
            : ($tested ?? $this->settings->for());

        $config = $this->settings->effectiveConfig();

        // A centrally managed organization gets exactly what the Call panel will do — the capability
        // resolver's own answer, which also honours CALL_PROVIDER — and the card shows status only, with
        // no credential form, because nobody there enters Twilio credentials. A client organization gets
        // the answer for its own account.
        $browserCalling = $centrallyManaged
            ? $this->providers->supportsBrowserCalling()
            : $config->isUsable();

        return response()->json([
            'data' => [
                // What will actually happen on the next call, in the same vocabulary the Call panel's
                // capability endpoint uses.
                'browserCalling' => $browserCalling,
                'provider' => $browserCalling ? TwilioCallProvider::NAME : 'manual',
                'centrallyManaged' => $centrallyManaged,

                // WHERE that configuration came from: this org's own row, the centralized account, or
                // nowhere at all.
                'source' => $config->source,
                'enabled' => $config->enabled,
                'complete' => $config->isComplete(),
                // Whether Twilio has ACCEPTED these credentials on an explicit connection test. The
                // third condition of browser calling, reported separately so the card can tell
                // "never checked" apart from "checked and rejected" — and from the two other ways a
                // configuration can be inert (switched off, or missing a field).
                'verified' => $config->isVerified(),
                // Field KEYS only. Never a value, and never a hint about the values that ARE present.
                'missing' => $config->missing(),
                'requiredFields' => TwilioConfig::REQUIRED_FIELDS,

                'settings' => new CallProviderSettingResource($row),
            ],
        ]);
    }
}
