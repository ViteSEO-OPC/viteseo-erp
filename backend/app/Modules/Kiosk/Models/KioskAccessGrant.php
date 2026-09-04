<?php

declare(strict_types=1);

namespace App\Modules\Kiosk\Models;

use App\Models\Concerns\BelongsToOrg;
use App\Models\Org;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A temporary kiosk access grant (a 24h demo login for a client). Org-scoped.
 */
class KioskAccessGrant extends Model
{
    use BelongsToOrg;

    protected $table = 'kiosk_access_grants';

    protected $fillable = [
        'org_id',
        'user_id',
        'created_by_user_id',
        'label',
        'expires_at',
        'revoked_at',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'revoked_at' => 'datetime',
    ];

    /** Not revoked and not yet expired. */
    public function isActive(): bool
    {
        return $this->revoked_at === null && $this->expires_at !== null && $this->expires_at->isFuture();
    }

    /** active | expired | revoked */
    public function status(): string
    {
        if ($this->revoked_at !== null) {
            return 'revoked';
        }

        return $this->expires_at !== null && $this->expires_at->isFuture() ? 'active' : 'expired';
    }

    public function org(): BelongsTo
    {
        return $this->belongsTo(Org::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }
}
