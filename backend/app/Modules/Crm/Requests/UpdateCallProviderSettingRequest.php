<?php

declare(strict_types=1);

namespace App\Modules\Crm\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validation for a change to an organization's paid-calling configuration.
 *
 * Route-authorized: the routes sit behind `role:super_admin|crm_admin`, so authorization is not
 * re-decided here — the same division {@see UpdateNerbyProviderSettingRequest} uses. What IS decided
 * here is the shape, and three choices are deliberate.
 *
 * ENABLING AN INCOMPLETE CONFIGURATION IS VALID. It is tempting to reject it, but that would make the
 * honest intermediate state unreachable: an admin who has decided to use Twilio but is waiting on the
 * API key from a colleague would have nowhere to record the decision. It is accepted instead, and the
 * capability resolver reports browser calling as unavailable while naming the missing fields — the
 * system says "selected but not operable" out loud rather than refusing to remember.
 *
 * THE SECRETS ARE WRITE-ONLY AND OPTIONAL. Omitting `auth_token` / `api_secret` keeps whatever is
 * stored, so correcting a caller ID or flipping the switch never requires a live secret to make
 * another round trip through a browser. Clearing them is a separate DELETE, because a blank field is
 * far more often "I didn't retype it".
 *
 * SID SHAPES ARE CHECKED, PREFIXES LOOSELY. Twilio's identifiers have well-known 34-character `AC…` /
 * `SK…` / `AP…` forms and a paste error is by far the most common misconfiguration, so the shape is
 * validated to catch it at the form rather than at dial time. The check stays deliberately narrow —
 * length and prefix only — because rejecting a valid future identifier would be worse than accepting
 * a malformed one the connection test then rejects.
 */
class UpdateCallProviderSettingRequest extends FormRequest
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

            // Identifiers. Nullable so a field can be deliberately cleared; `regex` is anchored on the
            // documented prefix and Twilio's fixed 34-character SID length.
            'account_sid' => ['sometimes', 'nullable', 'string', 'regex:/^AC[0-9a-fA-F]{32}$/'],
            'api_key' => ['sometimes', 'nullable', 'string', 'regex:/^SK[0-9a-fA-F]{32}$/'],
            'twiml_app_sid' => ['sometimes', 'nullable', 'string', 'regex:/^AP[0-9a-fA-F]{32}$/'],

            // E.164, the only form Twilio accepts as a caller ID.
            'caller_id' => ['sometimes', 'nullable', 'string', 'regex:/^\+[1-9]\d{7,14}$/'],

            // Twilio dials this from the public internet, so it must be an absolute https origin.
            // http is refused rather than merely discouraged: the callback carries call metadata and
            // is authenticated by a signature over the URL itself.
            'webhook_base_url' => ['sometimes', 'nullable', 'string', 'max:255', 'url', 'starts_with:https://'],

            // Secrets. Shape only — length and no whitespace, the same gate the Nearby key uses.
            'auth_token' => ['sometimes', 'nullable', 'string', 'min:20', 'max:255', 'regex:/^\S+$/'],
            'api_secret' => ['sometimes', 'nullable', 'string', 'min:20', 'max:255', 'regex:/^\S+$/'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'account_sid.regex' => 'A Twilio Account SID starts with AC and is 34 characters long.',
            'api_key.regex' => 'A Twilio API Key SID starts with SK and is 34 characters long.',
            'twiml_app_sid.regex' => 'A TwiML App SID starts with AP and is 34 characters long.',
            'caller_id.regex' => 'Use the full international form, e.g. +639171234567.',
            'webhook_base_url.starts_with' => 'The callback URL must be https — Twilio will not post call data over http.',
            'auth_token.min' => 'That does not look like an Auth Token. Paste the full value.',
            'auth_token.regex' => 'An Auth Token cannot contain spaces — check for a stray copy/paste.',
            'api_secret.min' => 'That does not look like an API Key Secret. Paste the full value.',
            'api_secret.regex' => 'An API Key Secret cannot contain spaces — check for a stray copy/paste.',
        ];
    }
}
