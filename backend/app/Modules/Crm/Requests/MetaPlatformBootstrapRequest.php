<?php

declare(strict_types=1);

namespace App\Modules\Crm\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * What an operator may say when connecting the platform's own Meta business.
 *
 * THE ALLOWLIST IS THE SECURITY BOUNDARY. Three fields, and every one of them is either proven
 * against Meta before use or checked against what Meta returned. `org_id` and `connection_id` are
 * explicitly PROHIBITED rather than merely absent: a rule that says nothing lets a future
 * `$request->all()` quietly widen the surface, while `prohibited` makes the attempt a validation
 * error somebody sees.
 *
 * THE TOKEN IS WRITE-ONLY. It arrives here once, goes straight into encrypted storage, and is
 * never echoed back by any endpoint. It is deliberately not length-bounded at the top end beyond a
 * sanity ceiling, because Meta has changed token formats before and a tight rule would reject a
 * valid credential for looking unfamiliar.
 */
class MetaPlatformBootstrapRequest extends FormRequest
{
    /** Authorization is the route's: auth:sanctum + org context + role:super_admin. */
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // Meta business ids are numeric strings. Checked again server-side by reading the
            // business back with the token — this rule only stops a malformed path segment.
            'business_id' => ['required', 'string', 'regex:/^[0-9]{1,32}$/'],

            'system_user_token' => ['required', 'string', 'min:20', 'max:2000'],

            // Which Pages / Instagram accounts to claim. Each is verified present in what Meta
            // returned for this grant before anything is written.
            'asset_ids' => ['required', 'array', 'min:1', 'max:50'],
            'asset_ids.*' => ['required', 'string', 'regex:/^[0-9]{1,32}$/'],

            // The tenant and the connection are server state, never a caller's choice.
            'org_id' => ['prohibited'],
            'tenant_id' => ['prohibited'],
            'connection_id' => ['prohibited'],
        ];
    }

    public function messages(): array
    {
        return [
            'org_id.prohibited' => 'The organization is resolved from your session and cannot be supplied.',
            'tenant_id.prohibited' => 'The organization is resolved from your session and cannot be supplied.',
            'connection_id.prohibited' => 'The connection is resolved server-side and cannot be supplied.',
        ];
    }
}
