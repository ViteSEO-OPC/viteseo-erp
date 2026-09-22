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
        ];
    }
}
