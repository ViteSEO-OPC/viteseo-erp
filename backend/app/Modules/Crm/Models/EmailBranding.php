<?php

namespace App\Modules\Crm\Models;

use App\Models\Concerns\BelongsToOrg;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An organization's email branding — one row per organization. Its logo is a pointer to an email asset, which
 * every design's logo block resolves through when an email renders.
 */
#[Fillable([
    'logo_asset_id',
    'updated_by',
])]
class EmailBranding extends Model
{
    use BelongsToOrg;

    protected $table = 'crm_email_brandings';

    /** @return BelongsTo<EmailAsset, $this> */
    public function logo(): BelongsTo
    {
        return $this->belongsTo(EmailAsset::class, 'logo_asset_id');
    }
}
