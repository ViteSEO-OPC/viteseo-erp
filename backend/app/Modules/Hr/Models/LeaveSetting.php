<?php

namespace App\Modules\Hr\Models;

use App\Models\Concerns\BelongsToOrg;
use Illuminate\Database\Eloquent\Model;

class LeaveSetting extends Model
{
    use BelongsToOrg;
    protected $table = 'hr_leave_settings';

    protected $fillable = [
        'allotment_mode',
    ];

    public const MODE_NOT_SHARED = 'not_shared';
    public const MODE_SHARED = 'shared';

    /** @var list<string> */
    public const MODES = [self::MODE_NOT_SHARED, self::MODE_SHARED];

    /** The singleton company leave settings row (created with defaults if absent). */
    public static function current(): self
    {
        return static::firstOrCreate([], ['allotment_mode' => self::MODE_NOT_SHARED]);
    }
}
