<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToOrg;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A custom role defined by an org (Custom Roles v2): a name + a set of capability
 * (permission) keys. Org-scoped, so an org's admin only sees and manages its own.
 *
 * Lives in App\Models, not inside a module. It was in Modules/Accounting because
 * accounting was the only thing it could grant; it is now the platform's role
 * builder for every module, and the `custom_roles` table never had anything
 * accounting-specific in it -- org_id, name, permissions.
 */
class CustomRole extends Model
{
    use BelongsToOrg;

    protected $table = 'custom_roles';

    protected $fillable = ['org_id', 'name', 'permissions'];

    protected $casts = [
        'permissions' => 'array',
    ];

    public function org(): BelongsTo
    {
        return $this->belongsTo(Org::class);
    }
}
