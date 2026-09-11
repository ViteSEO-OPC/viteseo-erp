<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Calling Workspace — transport selection
|--------------------------------------------------------------------------
|
| Zerem is fully usable with NO paid telephony provider. `manual` is the default
| and needs no account, no credentials and no subscription: the BD dials on a
| physical phone and logs the call, and the CRM keeps every part it always owned
| — the Planner event, the call record, the timeline, the disposition workflow
| and the call history.
|
| Browser calling is opt-in, and runs on ONE centralized ViteSEO Twilio account
| shared by every authorized CRM user (config/twilio.php — intentional):
|
|     CALL_PROVIDER=manual     (default — free, always works)
|     CALL_PROVIDER=twilio     (browser calling; requires config/twilio.php)
|
| Selecting a provider is NOT the same as having one. If `twilio` is selected but
| its credentials are absent or partial, CallProviderRegistry falls back to manual
| rather than breaking the CRM — a misconfigured deployment loses browser calling,
| never calling itself. That fallback is the whole point of this indirection.
|
| Adding a provider later means implementing CallProvider and adding one line to
| the map below. No CRM domain code changes.
|
*/

return [
    'provider' => env('CALL_PROVIDER', 'manual'),

    /*
     | Per-organization Twilio accounts (Settings → Integrations → Calling). OFF by
     | default: with `false`, stored per-organization credentials are ignored and the
     | centralized ViteSEO account is the only account ever used — for tokens,
     | dialing, caller ID and webhook signatures. Do not switch on without a product
     | decision.
     |
     | With `true`, each client organization is carried ONLY on its own account, and
     | an organization without one gets manual calling — never the centralized
     | account (see TwilioConfig::forOrg()).
     */
    'organization_credentials' => (bool) env('CALLING_ORGANIZATION_CREDENTIALS', false),

    /*
     | The ONE organization (ViteSEO's own) carried on the centralized account while
     | per-organization accounts are on. Its settings screen is read-only. Unset, or
     | not a positive integer, means NO organization uses the centralized account —
     | so set it before switching per-organization accounts on. Ignored while they
     | are off, when every organization uses the centralized account.
     */
    'central_org_id' => env('CALLING_CENTRAL_ORG_ID'),

    /*
     | Known transports. The key is what CALL_PROVIDER accepts AND what lands in
     | `crm_calls.provider`, so a stored row always names a real implementation.
     */
    'providers' => [
        \App\Modules\Crm\Services\Calling\ManualCallProvider::NAME => \App\Modules\Crm\Services\Calling\ManualCallProvider::class,
        \App\Modules\Crm\Services\Calling\TwilioCallProvider::NAME => \App\Modules\Crm\Services\Calling\TwilioCallProvider::class,
    ],
];
