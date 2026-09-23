<?php

declare(strict_types=1);

namespace App\Modules\Crm\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A provider secret at rest.
 *
 * NAMED FOR THE CONNECTION IT BELONGS TO, not simply `ProviderCredential`, because that name is
 * already taken by the outbound value object that carries a token in memory. Two classes one letter
 * apart, one of which must never be persisted and one of which is nothing but persistence, is a
 * confusion worth spending a longer name to avoid.
 *
 * THE PROTECTIONS ARE COPIED FROM {@see MailboxOauthToken} ON PURPOSE. That model is this
 * codebase's established answer to "where do OAuth secrets live": `encrypted` casts so the column
 * holds ciphertext, and a `$hidden` list so one accidental `toArray()` in a log line or an API
 * resource cannot leak a live token. Inventing a different scheme here would mean two conventions
 * for one problem, and the weaker one would eventually be the one someone copied.
 *
 * NEVER QUERY ON A TOKEN COLUMN. Each row is encrypted with its own IV, so equality matching cannot
 * work — two rows holding the same token have different ciphertext. Lookups go through the
 * plaintext foreign keys and `credential_type`, which is exactly why those columns are not
 * encrypted.
 *
 * NOT `BelongsToOrg`, matching {@see ProviderConnection}: this is read on queued paths where no
 * tenant is pinned, and an org filter that silently evaluated to null would look like "no
 * credential" rather than "no org context". Callers pass `org_id` explicitly.
 */
#[Fillable([
    'provider_connection_id',
    'provider_account_id',
    'credential_type',
    'access_token',
    'refresh_token',
    'expires_at',
    'scopes',
])]
class ProviderConnectionCredential extends Model
{
    /** A token scoped to one Page or Instagram account. What sending actually uses. */
    public const PAGE_ACCESS_TOKEN = 'page_access_token';

    /** The grant-level token. Exists to mint the above; never used to send. */
    public const USER_ACCESS_TOKEN = 'user_access_token';

    protected $table = 'crm_provider_credentials';

    /**
     * Keeps the secrets out of any array or JSON rendering of this model.
     *
     * @var list<string>
     */
    protected $hidden = [
        'access_token',
        'refresh_token',
    ];

    protected function casts(): array
    {
        return [
            'access_token' => 'encrypted',
            'refresh_token' => 'encrypted',
            'expires_at' => 'datetime',
            'scopes' => 'array',
        ];
    }

    /** Named to avoid Eloquent's own `$connection` property — see {@see ProviderAccount}.
     *
     * @return BelongsTo<ProviderConnection, $this>
     */
    public function providerConnection(): BelongsTo
    {
        return $this->belongsTo(ProviderConnection::class, 'provider_connection_id');
    }

    /** @return BelongsTo<ProviderAccount, $this> */
    public function account(): BelongsTo
    {
        return $this->belongsTo(ProviderAccount::class, 'provider_account_id');
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }
}
