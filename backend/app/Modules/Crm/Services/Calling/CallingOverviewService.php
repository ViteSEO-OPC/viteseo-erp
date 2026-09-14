<?php

declare(strict_types=1);

namespace App\Modules\Crm\Services\Calling;

use App\Models\Org;
use App\Modules\Crm\Models\CallProviderSetting;

/**
 * What calling is doing across EVERY organization — the platform operator's view, and the only one.
 *
 * ── Why this exists ──
 * An organization's own admin can see its Calling card; nobody could see all of them. That made the one
 * question support actually gets — "is this client's calling set up, and if not, what is missing?" —
 * unanswerable without a database session, because Zerem has no organization switcher and the settings
 * endpoints resolve the organization from the signed-in user's own membership. This answers it.
 *
 * ── READ-ONLY, and deliberately so ──
 * There is no cross-organization write anywhere in this class or the controller above it. A client's
 * Twilio account is the client's own property and their own bill; a platform operator who could edit it
 * from here could redirect a client's calls, and "support could see it" is not a reason to also be able
 * to change it. Every write stays where it is: the organization's own admin, on its own settings screen,
 * authenticated as that organization.
 *
 * ── NO SECRET, AND NO IDENTIFIER EITHER ──
 * This reports verdicts: where the configuration came from, whether it is switched on, complete and
 * verified, which required fields are empty (KEYS only), and the last connection-test status. It reads
 * no Auth Token and no API Secret — {@see TwilioConfig::fromSettings()} decrypts them into an object
 * this class never serializes — and it deliberately does not report the Account SID or API Key SID
 * either. They are not secrets, but a cross-tenant screen is the one place where "not a secret" stops
 * being a good enough reason to show one organization's identifiers to someone looking at another's.
 *
 * ── The tenant scope is lifted EXPLICITLY ──
 * Reading every organization is the entire purpose, so the scope is dropped by name rather than by
 * accident. It is safe here for the same reason it is safe in {@see TwilioConfig::forOrg()}: the caller
 * has already been authorized (`role:super_admin` on the route), the organization is read from the row
 * rather than asserted by a request, and nothing is written.
 */
class CallingOverviewService
{
    /**
     * The deployment's calling mode: which transport is selected, whether per-organization accounts are
     * on, which organization is central, and whether that configuration is sound.
     *
     * The misconfiguration is reported HERE rather than only in a log, because this is the screen where
     * an operator would be wondering why every organization suddenly says "manual calling".
     *
     * @return array<string, mixed>
     */
    public function mode(): array
    {
        $reason = TwilioConfig::misconfiguration();

        return [
            'provider' => (string) config('calling.provider', ManualCallProvider::NAME),
            'organizationCredentials' => TwilioConfig::organizationCredentialsEnabled(),
            'centralOrgId' => TwilioConfig::centralOrgId(),
            // Presence of the deployment's own TWILIO_* values. Incomplete is NOT a fault: a deployment
            // that has not bought a Twilio account runs manual calling, which is supported.
            'centralAccountComplete' => TwilioConfig::fromDeployment()->isComplete(),
            'misconfiguration' => $reason,
            'misconfigurationMessage' => $reason === null ? null : TwilioConfig::MISCONFIG_MESSAGES[$reason],
        ];
    }

    /**
     * One row per organization, in id order, each answering the same questions the organization's own
     * Calling card answers about itself.
     *
     * Every organization's stored row is loaded ONCE and matched in memory, rather than resolved with a
     * query per organization: this is a support screen, and an N+1 over the tenant list is how a support
     * screen becomes the slowest page in the product.
     *
     * @return list<array<string, mixed>>
     */
    public function organizations(): array
    {
        $rows = CallProviderSetting::query()
            ->withoutGlobalScope('org')
            ->where('provider', TwilioCallProvider::NAME)
            ->get()
            ->keyBy('org_id');

        return Org::query()
            ->orderBy('id')
            ->get(['id', 'name'])
            ->map(function (Org $org) use ($rows): array {
                $orgId = (int) $org->getKey();
                $centrallyManaged = TwilioConfig::isCentrallyManaged($orgId);
                $row = $rows->get($orgId);

                // Resolved exactly as a call would resolve it, so this screen can never disagree with
                // what the organization's BDs actually get.
                $config = TwilioConfig::forOrg($orgId);

                return [
                    'id' => $orgId,
                    'name' => (string) $org->name,

                    // What a BD in this organization gets on the next call.
                    'browserCalling' => $config->isUsable(),
                    'centrallyManaged' => $centrallyManaged,

                    'source' => $config->source,
                    'enabled' => $config->enabled,
                    'complete' => $config->isComplete(),
                    'verified' => $config->isVerified(),
                    // Field KEYS only — never a value, and never a hint about the values present.
                    'missing' => $config->missing(),

                    // Whether this organization has ever saved a row of its own. Reported even for a
                    // centrally managed organization, where the row is ignored: an operator looking at
                    // a stale row needs to know it is there AND that nothing reads it.
                    'configured' => $row !== null,
                    'storedRowIgnored' => $centrallyManaged && $row !== null,

                    'lastTestStatus' => $row?->last_test_status,
                    'lastTestedAt' => $row?->last_tested_at?->toIso8601String(),
                ];
            })
            ->values()
            ->all();
    }
}
