<?php

namespace App\Modules\Crm\Models;

use App\Models\Concerns\BelongsToOrg;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * An immutable image designed emails show — currently the organization logo.
 *
 * `path` is the PNG every email client receives, re-encoded on the server; `svg_path` is the sanitized,
 * cropped SVG kept alongside an SVG upload. `uuid` is the only identifier a rendered email ever carries: the
 * public email-asset route serves the PNG by it. `meta` records the upload: the original size, the crop, and
 * what the SVG sanitizer removed.
 */
#[Fillable([
    'uuid',
    'purpose',
    'source_format',
    'path',
    'svg_path',
    'width',
    'height',
    'bytes',
    'sha256',
    'meta',
    'created_by',
])]
class EmailAsset extends Model
{
    use BelongsToOrg;
    use SoftDeletes;

    public const PURPOSE_LOGO = 'logo';

    public const FORMAT_PNG = 'png';

    public const FORMAT_SVG = 'svg';

    protected $table = 'crm_email_assets';

    protected function casts(): array
    {
        return [
            'width' => 'integer',
            'height' => 'integer',
            'bytes' => 'integer',
            'meta' => 'array',
        ];
    }
}
