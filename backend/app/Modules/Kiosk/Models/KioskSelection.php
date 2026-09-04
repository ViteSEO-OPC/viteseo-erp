<?php

declare(strict_types=1);

namespace App\Modules\Kiosk\Models;

use App\Models\Concerns\BelongsToOrg;
use App\Models\Org;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A saved configurator selection = a captured lead. Org-scoped; the `sections`
 * JSON holds the chosen building blocks ({header, hero, footer, pages[]}).
 */
class KioskSelection extends Model
{
    use BelongsToOrg;
    use SoftDeletes;

    protected $table = 'kiosk_selections';

    protected $fillable = [
        'org_id',
        'created_by_user_id',
        'prospect_name',
        'prospect_contact',
        'notes',
        'business_type',
        'website_type',
        'sections',
        'brand_kit',
        'typography',
        'type_scale',
        'design_style',
        'heading_style',
        'radius',
        'cta_hover',
        'logo',
    ];

    protected $casts = [
        'sections' => 'array',
    ];

    public function org(): BelongsTo
    {
        return $this->belongsTo(Org::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }
}
