<?php

namespace App\Modules\Crm\Models;

use App\Models\Concerns\BelongsToOrg;
use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One CRM (Zerem) suspension. The row's EXISTENCE is the state — there is no `status` column and no
 * "active" row, so nothing here can disagree with anything else about whether a user is suspended.
 *
 * Deliberately thin: no business logic, no scopes for reading access state. Suspending, restoring
 * and asking "is this user suspended?" all go through {@see \App\Modules\Crm\Services\CrmAccessService},
 * which is the single authority on CRM access and carries the invariant that matters — that none of
 * it ever writes the shared `users` row. Querying this model directly from a controller would route
 * around that guarantee.
 *
 * NOT soft-deleted. Restoring CRM access removes the suspension outright; a trashed suspension row
 * would be a third state ("suspended, but not really") that the presence-is-the-state design exists
 * to avoid. Who suspended whom and when is recorded in the CRM Activity trail, not by keeping
 * tombstones here.
 *
 * @property int $id
 * @property int $user_id
 * @property Carbon $suspended_at Cast to datetime; declared so static analysis applies the cast.
 * @property int|null $suspended_by Null means the actor is unknown — a pre-migration backfill row.
 */
#[Fillable(['suspended_at', 'suspended_by'])]
class CrmUserAccess extends Model
{
    use BelongsToOrg;
    protected $table = 'crm_user_access';

    /**
     * `user_id` is absent from Fillable on purpose. It is the identity of the row, set by
     * associating the relationship in the service, never mass-assigned from request data.
     */
    protected function casts(): array
    {
        return [
            'suspended_at' => 'datetime',
        ];
    }

    /** The suspended account. */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** The admin who suspended them, when one is recorded. */
    public function suspendedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'suspended_by');
    }
}
