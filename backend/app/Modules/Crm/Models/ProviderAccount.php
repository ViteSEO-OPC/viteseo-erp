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
    'provider',
    'resource_type',
    'external_id',
    'label',
    'is_active',
])]
class ProviderAccount extends Model
{
    protected $table = 'crm_provider_accounts';

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
