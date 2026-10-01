<?php

namespace App\Modules\Crm\Models;

use App\Models\Concerns\BelongsToOrg;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A reusable email design: the look designed email templates share.
 *
 * `layout` is a validated EmailDesignSchema layout — styles, header, signature area and footer — never HTML
 * and never a template's content. Templates reference a design through `email_design_id`, so a change to it
 * reaches every template that uses it. Exactly one design per organization is the default
 * (EmailDesignService::defaultDesign()).
 */
#[Fillable([
    'name',
    'is_default',
    'layout',
    'created_by',
])]
class EmailDesign extends Model
{
    use BelongsToOrg;
    use SoftDeletes;

    protected $table = 'crm_email_designs';

    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
            'layout' => 'array',
        ];
    }

    /** @return HasMany<EmailTemplate, $this> */
    public function templates(): HasMany
    {
        return $this->hasMany(EmailTemplate::class, 'email_design_id');
    }
}
