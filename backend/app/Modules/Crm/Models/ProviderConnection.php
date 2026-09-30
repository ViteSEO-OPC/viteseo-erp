<?php

declare(strict_types=1);

namespace App\Modules\Crm\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One tenant's authorization with one provider.
 *
 * DELIBERATELY NOT `BelongsToOrg`, for the same reason {@see ProviderAccount} is not: both are read
 * on paths where no tenant is pinned yet, and a global scope keyed on `CurrentOrg::id()` returns
 * nothing outside an HTTP request — which for a queued outbound send would look like "this org has
 * no connection" rather than "the scope had no org". Every read in this module supplies `org_id`
 * explicitly instead, and the tests assert that isolation rather than inheriting it.
 *
 * `org_id` IS NOT FILLABLE. Ownership is set once, explicitly. A mass assignment that could move a
 * connection between tenants is the operation that must never be incidental.
 */
#[Fillable([
    'provider',
    'status',
    'external_business_id',
    'connected_by',
    'connected_at',
    'disconnected_at',
    'token_expires_at',
    'metadata',
])]
class ProviderConnection extends Model
{
    /** Authorized. Resources under this connection may be used for new outbound sends. */
    public const CONNECTED = 'connected';

    /** Revoked or deliberately unlinked. Blocks new sends; keeps every historical row intact. */
    public const DISCONNECTED = 'disconnected';

    /** The provider rejected our credential. Needs a human, not a retry. */
    public const ERROR = 'error';

    /**
     * How the connection came to exist. Recorded in `metadata.origin`.
     *
     * TWO LEGITIMATE ORIGINS, AND THE DISTINCTION IS NOT COSMETIC. Meta will not let the Business
     * Portfolio that owns an app onboard itself through Facebook Login for Business — it disables
     * that portfolio in the consent screen and says so. So the platform's own Page and Instagram
     * account reach the CRM through the documented first-party mechanism instead: a System User
     * token generated in Business Settings. Everything downstream treats the two identically; what
     * differs is how the credential is renewed, which is why the row says which it is.
     */
    public const ORIGIN_PLATFORM_BOOTSTRAP = 'platform_bootstrap';

    public const ORIGIN_CLIENT_OAUTH = 'client_oauth';

    protected $table = 'crm_provider_connections';

    protected function casts(): array
    {
        return [
            'connected_at' => 'datetime',
            'disconnected_at' => 'datetime',
            'token_expires_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    /** @return HasMany<ProviderAccount, $this> */
    public function accounts(): HasMany
    {
        return $this->hasMany(ProviderAccount::class, 'connection_id');
    }

    /** @return HasMany<ProviderConnectionCredential, $this> */
    public function credentials(): HasMany
    {
        return $this->hasMany(ProviderConnectionCredential::class, 'provider_connection_id');
    }

    /** @return BelongsTo<User, $this> */
    public function connectedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'connected_by');
    }

    /**
     * Whether new outbound sends may use this connection.
     *
     * Expiry counts as unusable even while the status still says connected: a token whose
     * `token_expires_at` has passed will be refused by the provider, and discovering that by
     * sending is worse than refusing here.
     */
    /** How this connection was established. Null for rows written before origins were recorded. */
    public function origin(): ?string
    {
        $origin = ($this->metadata ?? [])['origin'] ?? null;

        return is_string($origin) && $origin !== '' ? $origin : null;
    }

    public function isUsableForOutbound(): bool
    {
        if ($this->status !== self::CONNECTED) {
            return false;
        }

        return $this->token_expires_at === null || $this->token_expires_at->isFuture();
    }

    /**
     * Mark this connection unusable without destroying anything.
     *
     * Resources keep their rows and their `is_active` flags, conversations keep pointing at them,
     * and history keeps its provenance. Only the ability to start a NEW send goes away.
     */
    public function disconnect(): void
    {
        $this->status = self::DISCONNECTED;
        $this->disconnected_at = now();
        $this->save();
    }
}
