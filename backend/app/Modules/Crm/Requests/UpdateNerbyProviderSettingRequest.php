<?php

namespace App\Modules\Crm\Requests;

use App\Modules\Crm\Enums\NerbyProvider;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validation for a Nearby provider-configuration change (enablement, credential, options).
 *
 * Route-authorized: the routes sit behind `role:super_admin|crm_admin`, so authorization is not
 * re-decided here — the same division the enrichment ingest request uses. What IS decided here is
 * the shape, and two choices are deliberate.
 *
 * ENABLING A PROVIDER WITHOUT A CREDENTIAL IS VALID. It is tempting to reject it, but that would make
 * the honest intermediate state unreachable: an admin who has decided to use a provider but is
 * waiting on a key would have nowhere to record the decision. Instead it is accepted and the resolver
 * reports `provider_not_configured` with `mode: preview`, so the system says "selected but not
 * operable" out loud rather than refusing to remember.
 *
 * THE CREDENTIAL IS WRITE-ONLY AND OPTIONAL. Omitting it keeps whatever is stored, so changing a
 * model or flipping the switch never requires the key to make another round trip through a browser.
 * Clearing it is a separate DELETE, because a blank field is far more often "I didn't retype it".
 */
class UpdateNerbyProviderSettingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Route-level `role:super_admin|crm_admin` authorizes.
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'enabled' => ['sometimes', 'boolean'],

            // Shape only — length and no whitespace. Deliberately NOT a `sk-` prefix check: the
            // prefix is a vendor convention that has already changed once, and rejecting a valid
            // future key would be worse than accepting a malformed one the connection test rejects.
            // Provider-neutral, so a Places key passes the same gate.
            'api_key' => ['sometimes', 'nullable', 'string', 'min:20', 'max:255', 'regex:/^\S+$/'],

            // Provider-specific settings. Unknown keys are dropped by the service rather than
            // rejected here — the accepted set belongs to the provider, and duplicating that list in
            // validation would be a second source of truth that drifts the moment one is added.
            'options' => ['sometimes', 'nullable', 'array'],
            // Bounded so the JSON column cannot be used as free storage. Not an allow-list of model
            // names: models are released and retired on the vendor's schedule, and a hard-coded list
            // would turn a config edit into a deploy.
            'options.*' => ['nullable', 'string', 'max:120'],

            // TARGETED RULES FOR THE SPEND KEYS — alongside `options.*`, not instead of it. Laravel
            // applies both, and "10.00" satisfies both: a string under 120 characters AND a number
            // within the ceiling.
            //
            // This does NOT reintroduce the second source of truth the comment above rejects. Which
            // keys may be stored is still decided in exactly one place (NerbyProvider::optionKeys());
            // what is added here is a constraint on the SHAPE of one semantically special value.
            // `spend_limit_usd` is the only option in this system that is a spending authorisation,
            // and its upper bound is a deployment decision rather than a product one — so it is the
            // one option whose value a browser may not be trusted to choose freely.
            'options.spend_limit_usd' => [
                'sometimes', 'nullable', 'numeric', 'min:0',
                'max:'.config('nerby.spend.limit_max_usd', 500),
            ],
            // The pricing model. Overridable per deployment, but never to zero or a negative: a
            // price of 0 would make any budget buy infinite requests, which is the failure the gate
            // exists to prevent. The gate refuses outright rather than dividing by it.
            'options.price_per_1000_usd' => ['sometimes', 'nullable', 'numeric', 'gt:0'],
            'options.free_tier_requests' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:1000000'],
            // >= 1 by definition: a safety factor below 1 would inflate the allowance rather than
            // shrink it, turning a margin of safety into a margin of overspend.
            'options.safety_factor' => ['sometimes', 'nullable', 'numeric', 'min:1', 'max:10'],
        ];
    }

    /** The provider named in the route, validated against the enum. */
    public function provider(): NerbyProvider
    {
        return NerbyProvider::from((string) $this->route('provider'));
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'api_key.min' => 'That does not look like an API key. Paste the full key.',
            'api_key.regex' => 'An API key cannot contain spaces — check for a stray copy/paste.',
            'options.spend_limit_usd.max' => 'That is above the maximum spend limit this environment allows (:max USD). Raising the ceiling is a deployment change, not a settings change.',
            'options.spend_limit_usd.numeric' => 'The spend limit must be an amount in USD, for example 50 or 50.00.',
            'options.price_per_1000_usd.gt' => 'The price per 1,000 requests must be greater than zero — a price of zero would make any budget unlimited.',
            'options.safety_factor.min' => 'The safety factor must be at least 1. Below 1 it would enlarge the allowance instead of protecting it.',
        ];
    }
}
