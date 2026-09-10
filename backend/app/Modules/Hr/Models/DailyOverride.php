<?php

namespace App\Modules\Hr\Models;

use App\Models\Concerns\BelongsToOrg;
use Illuminate\Database\Eloquent\Model;

class DailyOverride extends Model
{
    use BelongsToOrg;
    protected $table = 'hr_daily_overrides';

    protected $fillable = ['date', 'grace_minutes', 'min_full_day_minutes'];

    protected $casts = [
        'date' => 'date',
        'grace_minutes' => 'integer',
        'min_full_day_minutes' => 'integer',
    ];
}
