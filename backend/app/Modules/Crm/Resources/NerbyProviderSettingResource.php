<?php

namespace App\Modules\Crm\Resources;

use App\Modules\Crm\Enums\NerbyProvider;
use App\Modules\Crm\Models\NerbyProviderSetting;
use App\Modules\Crm\Services\Discovery\GooglePlacesSpendGate;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * THE NO-LEAK BOUNDARY for one provider's Nearby configuration.
 *
 * Built as an ALLOW-LIST of safe fields, not as a filter over the model's attributes. The difference
 * is the whole safety argument: a filter is one forgotten `unset` — or one new column — away from
 * leaking a credential, whereas an allow-list leaks only what someone deliberately adds. Nothing here
 * reads `api_key`, and there is no `...$this->resource` spread through which it could arrive.
 *
 * What a client learns about the credential is exactly: whether one exists, and its last 4
 * characters. Not its length, not its prefix, not its ciphertext, not when it was created. The same
 * discipline the Gmail integration status follows ("no token, no expiry, no ciphertext").
 *
 * @mixin NerbyProviderSetting
 */
class NerbyProviderSettingResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $provider = $this->resource->providerEnum();

        return [
            'provider' => $provider->value,
            'label' => $provider->label(),
            // Lets a UI render the right controls without hard-coding which providers need a key —
            // so a provider added later gets its credential field for free.
            'requiresCredentials' => $provider->requiresCredentials(),
            'optionKeys' => $provider->optionKeys(),

            'isActive' => (bool) $this->is_active,
            'enabled' => (bool) $this->enabled,
            'options' => $this->options ?? [],

            // -- credential PRESENCE only. Never the credential. --
            'hasApiKey' => $this->resource->hasApiKey(),
            'apiKeyLast4' => $this->key_last4,

            'lastTestStatus' => $this->last_test_status,
            'lastTestedAt' => $this->last_tested_at?->toIso8601String(),

            // Whether this provider has ever been configured — distinct from being enabled.
            'configured' => $this->resource->exists,
            'updatedAt' => $this->updated_at?->toIso8601String(),

            // WHAT THE BUDGET IS AND WHAT IT HAS BOUGHT, for the provider that has one. Present only
            // for Google Places, so no other provider's card grows a meaningless meter.
            //
            // Reported in DOLLARS AND REQUESTS TOGETHER, deliberately. The dollar figure is a model —
            // the budget divided by a configured price — and an operator who sees only dollars cannot
            // tell a stale price constant from real spending, because a wrong price is invisible in
            // the very number it distorts. Seeing both makes the translation checkable.
            'spend' => $provider === NerbyProvider::GOOGLE_PLACES
                ? app(GooglePlacesSpendGate::class)->state()
                : null,
        ];
    }
}
