<?php

declare(strict_types=1);

namespace App\Modules\Crm\Models;

use App\Models\Org;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An external provider resource (a Facebook Page, an Instagram business account) and the CRM tenant
 * that owns it.
 *
 * DELIBERATELY NOT `BelongsToOrg`. Every other CRM model carries that trait so a forgotten
 * `where('org_id', …)` cannot leak across tenants. This one must not: it is the table consulted to
 * DISCOVER the tenant, before any tenant context exists. Scoping it to the current org would make
 * the lookup circular — and worse, would make it silently return nothing on the public webhook
 * path, where nothing is pinned yet.
 *
 * That is safe because of how it is read: only ever by (provider, resource_type, external_id),
 * which the database guarantees is unique platform-wide, so a lookup returns one row or none. It is
 * never listed or searched without an org filter supplied explicitly by an admin surface.
 *
 * `org_id` is not fillable: ownership is set once, explicitly, by the provisioning command. A mass
 * assignment that could move a Page between tenants is exactly the operation that must never be
 * incidental.
 */
#[Fillable([
    'connection_id',
    'provider',
    'resource_type',
    'external_id',
    'label',
    'is_active',
])]
class ProviderAccount extends Model
{
    protected $table = 'crm_provider_accounts';

    /**
     * The grant this mapping came from, when it came from one.
     *
     * NULL for the resources an operator provisioned by hand before connections existed, which
     * reads correctly as "mapped by a person, not by an OAuth flow". Nothing on the inbound path
     * follows this relation: webhook ownership must stay answerable when a tenant's outbound grant
     * has lapsed, so tying delivery to a connection's health is precisely the coupling avoided.
     *
     * NAMED `providerConnection`, NOT `connection`. Eloquent's Model already defines a protected
     * `$connection` property holding the database connection name, so a relation of that name is
     * shadowed: `$account->connection` returns the string "mysql", and any method call on it is a
     * fatal error at runtime rather than a failure a type checker would catch.
     *
     * @return BelongsTo<ProviderConnection, $this>
     */
    public function providerConnection(): BelongsTo
    {
        return $this->belongsTo(ProviderConnection::class, 'connection_id');
    }

    /**
     * Whether this resource may be used to START a new outbound send.
     *
     * A hand-provisioned resource with no connection is usable — that is how the two production
     * mappings work today. A resource whose connection is disconnected, errored or expired is not.
     */
    public function isUsableForOutbound(): bool
    {
        if (! $this->is_active) {
            return false;
        }

        if ($this->connection_id === null) {
            return true;
        }

        return $this->providerConnection?->isUsableForOutbound() ?? false;
    }

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function org(): BelongsTo
    {
        return $this->belongsTo(Org::class);
    }
}
