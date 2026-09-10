<?php

namespace App\Modules\Crm\Models;

use App\Models\Concerns\BelongsToOrg;
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
 * Only `body` and `body_doc` are mass-assignable; `lead_id` is set via the relationship and
 * `user_id` (author) is associated explicitly in the controller — neither is client-writable.
 *
 * `body` is always the canonical readable text and is never null. `body_doc` holds the Tiptap
 * document when a note was composed in the rich editor, and is NULL for every legacy and imported
 * note — that nullability is the format discriminator, so no note ever needs converting.
 *
 * The two are not independent: whenever `body_doc` is written, `body` must be the server-derived
 * projection of it ({@see \App\Support\TiptapDocument::extractText()}), never a string the client
 * supplied. {@see \App\Modules\Crm\Services\LeadNoteCreator} is where that is enforced.
 */
#[ObservedBy([NoteObserver::class])]
#[Fillable(['body', 'body_doc'])]
class Note extends Model
{
    use BelongsToOrg;
    protected $table = 'crm_notes';

    use SoftDeletes;

    /** @var array<string, string> */
    protected $casts = [
        'body_doc' => 'array',
    ];

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
