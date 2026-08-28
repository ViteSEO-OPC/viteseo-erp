<?php

namespace App\Modules\Crm\Models;

use App\Models\User;
use App\Modules\Crm\Enums\NerbyProvider;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One provider's Nearby configuration. One row per provider, not one row of settings.
 *
 * PERSISTENCE ONLY, deliberately. Which provider is EFFECTIVE — and why it may differ from the one
 * selected — depends on whether credentials are usable, and a model that answered that from a
 * property access would hide a fallback behind an attribute read. That decision lives in
 * {@see \App\Modules\Crm\Services\Discovery\NerbyProviderSettingService}, which returns the reason
 * alongside the answer. The single-active-row invariant is enforced there too.
 *
 * `api_key` is ENCRYPTED at rest and plaintext in PHP. Two consequences, both load-bearing:
 *
 *   - It must NEVER be queried with a WHERE clause. Every row gets a distinct IV, so equality
 *     matching cannot work — the same trap documented on {@see MailboxOauthToken}. Use `key_last4`
 *     for anything that needs to compare or display.
 *   - It must NEVER reach an API response. The resource layer is the enforcement point
 *     ({@see \App\Modules\Crm\Resources\NerbyProviderSettingResource}), built as an allow-list of
 *     safe fields rather than a filter over the model's attributes — a filter is one forgotten
 *     `unset` (or one new column) away from leaking; an allow-list is not.
 *
 * @property string $provider
 * @property bool $is_active
 * @property bool $enabled
 * @property string|null $api_key Encrypted at rest; plaintext when read. Never serialize this.
 * @property string|null $key_last4 Display/diagnostic hint. Plain text, safe to return.
 * @property array<string, mixed>|null $options provider-specific settings (e.g. OpenAI model)
 * @property Carbon|null $last_tested_at Cast to datetime; declared so static analysis applies the cast.
 * @property string|null $last_test_status
 * @property int|null $updated_by
 */
#[Fillable([
    'provider',
    'is_active',
    'enabled',
    'api_key',
    'key_last4',
    'options',
    'last_tested_at',
    'last_test_status',
    'updated_by',
])]
class NerbyProviderSetting extends Model
{
    protected $table = 'crm_nerby_provider_settings';

    /**
     * Model-level defaults kept in lockstep with the migration's column defaults.
     *
     * Declared here as well as in the DB because Eloquent does not read database defaults back into a
     * new instance: without these, a freshly instantiated (unsaved) row would report `is_active` and
     * `enabled` as null and every caller would have to special-case "not saved yet".
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'is_active' => false,
        'enabled' => false,
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'enabled' => 'boolean',
            'api_key' => 'encrypted',
            'options' => 'array',
            'last_tested_at' => 'datetime',
        ];
    }

    /**
     * Belt-and-braces behind the resource. `$hidden` means even a stray `->toArray()` or a debug dump
     * in a log cannot surface the credential.
     *
     * @var list<string>
     */
    protected $hidden = ['api_key'];

    /** @return BelongsTo<User, $this> */
    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /** The typed provider this row configures, degrading safely for an unrecognised value. */
    public function providerEnum(): NerbyProvider
    {
        return NerbyProvider::fromStringOrDefault($this->provider);
    }

    /** Whether a credential is stored. Never exposes the credential itself. */
    public function hasApiKey(): bool
    {
        return filled($this->api_key);
    }

    /** One provider-specific option, or null. */
    public function option(string $key): mixed
    {
        return ($this->options ?? [])[$key] ?? null;
    }
}
