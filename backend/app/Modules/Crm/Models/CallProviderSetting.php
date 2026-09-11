<?php

declare(strict_types=1);

namespace App\Modules\Crm\Models;

use App\Models\Concerns\BelongsToOrg;
use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One organization's configuration for one paid call transport. Persistence only.
 *
 * WHETHER BROWSER CALLING IS ACTUALLY AVAILABLE is deliberately not answered here. That depends on
 * completeness, enablement and the deployment-wide fallback, and a model that answered it from a
 * property read would hide a fallback behind an attribute access. It lives in
 * {@see \App\Modules\Crm\Services\Calling\TwilioConfig}, which returns the reason alongside the answer
 * — the same division {@see NerbyProviderSetting} makes with its service.
 *
 * `auth_token` and `api_secret` are ENCRYPTED at rest and plaintext in PHP. Two consequences, both
 * load-bearing:
 *
 *   - They must NEVER be queried with a WHERE clause. Every row gets a distinct IV, so equality
 *     matching cannot work — the trap documented on {@see MailboxOauthToken}. Rows are found by
 *     (org, provider), which is what the unique index is for.
 *   - They must NEVER reach an API response. The enforcement point is
 *     {@see \App\Modules\Crm\Resources\CallProviderSettingResource}, built as an ALLOW-LIST of safe
 *     fields rather than a filter over the model's attributes — a filter is one forgotten `unset`, or
 *     one new column, away from leaking; an allow-list is not.
 *
 * @property int $org_id
 * @property string $provider
 * @property bool $enabled
 * @property string|null $account_sid Identifier, plain. Safe to display.
 * @property string|null $api_key The SK… key sid. Identifier, plain. Safe to display.
 * @property string|null $twiml_app_sid Identifier, plain. Safe to display.
 * @property string|null $caller_id The verified number shown to the callee. Plain.
 * @property string|null $webhook_base_url The public origin Twilio calls back on. Plain.
 * @property string|null $auth_token Encrypted at rest; plaintext when read. Never serialize this.
 * @property string|null $api_secret Encrypted at rest; plaintext when read. Never serialize this.
 * @property string|null $auth_token_last4 Display hint. Plain, safe to return.
 * @property string|null $api_secret_last4 Display hint. Plain, safe to return.
 * @property Carbon|null $last_tested_at Cast to datetime; declared so static analysis applies the cast.
 * @property string|null $last_test_status
 * @property int|null $updated_by
 */
#[Fillable([
    'provider',
    'enabled',
    'account_sid',
    'api_key',
    'twiml_app_sid',
    'caller_id',
    'webhook_base_url',
    'auth_token',
    'api_secret',
    'auth_token_last4',
    'api_secret_last4',
    'last_tested_at',
    'last_test_status',
    'updated_by',
])]
class CallProviderSetting extends Model
{
    use BelongsToOrg;

    /** Explicit: Eloquent would otherwise derive `call_provider_settings` and miss the `crm_` prefix. */
    protected $table = 'crm_call_provider_settings';

    /**
     * Model-level default kept in lockstep with the migration's column default. Eloquent does not
     * read database defaults back into a new instance, so without this a freshly instantiated
     * (unsaved) row would report `enabled` as null and every caller would special-case "not saved".
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'enabled' => false,
    ];

    /**
     * Belt-and-braces behind the resource: even a stray `->toArray()`, a `dd()` or a model dumped
     * into a log line cannot surface either secret.
     *
     * @var list<string>
     */
    protected $hidden = ['auth_token', 'api_secret'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'auth_token' => 'encrypted',
            'api_secret' => 'encrypted',
            'last_tested_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /** Whether an auth token is stored. Never exposes it. */
    public function hasAuthToken(): bool
    {
        return filled($this->auth_token);
    }

    /** Whether an API secret is stored. Never exposes it. */
    public function hasApiSecret(): bool
    {
        return filled($this->api_secret);
    }
}
