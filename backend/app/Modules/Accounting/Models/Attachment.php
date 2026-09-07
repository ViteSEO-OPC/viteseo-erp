<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Models;

use App\Models\Concerns\BelongsToOrg;

use App\Models\Org;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A file (receipt / document) attached to an invoice or bill. Files live on a
 * PRIVATE disk and are served only through the gated download endpoint.
 */
class Attachment extends Model
{
    use BelongsToOrg;
    protected $table = 'acc_attachments';

    protected $fillable = [
        'org_id',
        'attachable_type',
        'attachable_id',
        'original_name',
        'disk',
        'path',
        'mime_type',
        'size',
        'uploaded_by',
    ];

    protected $casts = [
        'size' => 'integer',
    ];

    public function org(): BelongsTo
    {
        return $this->belongsTo(Org::class);
    }
}
