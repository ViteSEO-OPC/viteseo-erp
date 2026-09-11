<?php

declare(strict_types=1);

namespace App\Modules\Crm\Resources;

use App\Modules\Crm\Models\CallProviderSetting;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * THE NO-LEAK BOUNDARY for one organization's paid-calling configuration.
 *
 * Built as an ALLOW-LIST of safe fields, not as a filter over the model's attributes. The difference
 * is the whole safety argument: a filter is one forgotten `unset` — or one new column — away from
 * leaking a credential, whereas an allow-list leaks only what someone deliberately adds. Nothing here
 * reads `auth_token` or `api_secret`, and there is no `...$this->resource` spread through which one
 * could arrive.
 *
 * What a client learns about each secret is exactly: whether one exists, and its last 4 characters.
 * Not its length, not its prefix, not its ciphertext, not when it was created. The same discipline the
 * Gmail integration and {@see NerbyProviderSettingResource} follow.
 *
 * The IDENTIFIERS are returned in full, on purpose. `account_sid`, `api_key` (the SK… sid),
 * `twiml_app_sid`, `caller_id` and `webhook_base_url` are values Twilio itself displays in its console
 * and puts in webhook payloads, and an admin has to be able to see them to confirm which account is
 * wired up and to spot a paste error. None of them can place a call or sign anything on its own.
 *
 * @mixin CallProviderSetting
 */
class CallProviderSettingResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'provider' => $this->resource->provider,
            'enabled' => (bool) $this->enabled,

            // -- identifiers: safe to display --
            'accountSid' => $this->account_sid,
            'apiKey' => $this->api_key,
            'twimlAppSid' => $this->twiml_app_sid,
            'callerId' => $this->caller_id,
            'webhookBaseUrl' => $this->webhook_base_url,

            // -- secret PRESENCE only. Never the secrets. --
            'hasAuthToken' => $this->resource->hasAuthToken(),
            'authTokenLast4' => $this->auth_token_last4,
            'hasApiSecret' => $this->resource->hasApiSecret(),
            'apiSecretLast4' => $this->api_secret_last4,

            'lastTestStatus' => $this->last_test_status,
            'lastTestedAt' => $this->last_tested_at?->toIso8601String(),

            // Whether this org has ever configured this provider — distinct from having enabled it.
            'configured' => $this->resource->exists,
            'updatedAt' => $this->updated_at?->toIso8601String(),
        ];
    }
}
