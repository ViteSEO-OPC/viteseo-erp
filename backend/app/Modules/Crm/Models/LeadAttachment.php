<?php

namespace App\Modules\Crm\Models;

use App\Models\Concerns\BelongsToOrg;
use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A file uploaded against a lead. The row is metadata only — the bytes live on the private disk
 * under {@see LeadAttachment::$path}, which is why nothing here is client-writable: `path`,
 * `original_name`, `mime_type` and `size` are all derived from the UploadedFile by
 * {@see \App\Modules\Crm\Services\LeadAttachmentStore}, and `lead_id`/`user_id` are set from the
 * route's lead and the authenticated user. An empty fillable list keeps that guarantee explicit
 * rather than relying on callers to pass the right array.
 */
#[Fillable([])]
class LeadAttachment extends Model
{
    use BelongsToOrg;
    protected $table = 'crm_lead_attachments';

    use SoftDeletes;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            // Bytes arrive from the DB as a string on some drivers; cast so the Resource and any
            // size arithmetic always see an int.
            'size' => 'integer',
        ];
    }

    /** @return BelongsTo<Lead, $this> */
    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    /** @return BelongsTo<User, $this> */
    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
