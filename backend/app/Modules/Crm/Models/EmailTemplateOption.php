<?php

namespace App\Modules\Crm\Models;

use App\Models\Concerns\BelongsToOrg;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A custom email-template purpose or tone an organization added to the built-in catalog. Templates store the `value`
 * string itself, as they store built-in values; this row is what makes the value selectable and reusable.
 * EmailTemplateOptionService owns validation, normalization, collisions and deletion.
 */
#[Fillable([
    'type',
    'value',
    'normalized_value',
    'created_by',
])]
class EmailTemplateOption extends Model
{
    use BelongsToOrg;
    use SoftDeletes;

    protected $table = 'crm_email_template_options';
}
