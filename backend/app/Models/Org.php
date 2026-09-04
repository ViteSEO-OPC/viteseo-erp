<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Tenant container. Each customer-company = one org.
 * Users belong to one or more orgs via the `org_user` pivot; the current org
 * for a request is resolved by {@see \App\Support\CurrentOrg}.
 */
class Org extends Model
{
    protected $table = 'orgs';

    protected $fillable = [
        'name',
        'is_active',
        // Legal identity shown on customer-facing documents (invoice letterhead).
        'tin',
        'address',
        'is_vat_registered',
        'default_tax_rate',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'is_vat_registered' => 'boolean',
        'default_tax_rate' => 'decimal:2',
    ];

    /** Users (members) of this org. */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'org_user')
            ->withPivot('is_default')
            ->withTimestamps();
    }
}
