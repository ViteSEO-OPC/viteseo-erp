<?php

namespace App\Modules\Crm\Models;

use App\Models\User;

use App\Modules\Crm\Observers\NoteObserver;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A free-text internal note attached to a lead. This is the mutable content store; each
 * create/update/delete also logs an append-only `note.*` Activity (see NoteObserver) against the
 * lead, so notes flow through the existing Activity ownership/audit architecture unchanged.
 *
 * Only `body` is mass-assignable; `lead_id` is set via the relationship and `user_id` (author)
 * is associated explicitly in the controller — neither is client-writable.
 */
#[ObservedBy([NoteObserver::class])]
#[Fillable(['body'])]
class Note extends Model
{
    protected $table = 'crm_notes';

    use SoftDeletes;

    /** @return BelongsTo<Lead, $this> */
    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    /** @return BelongsTo<User, $this> */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
