<?php

namespace App\Modules\Hr\Models;

use Illuminate\Database\Eloquent\Model;

class DailyOverride extends Model
{
    protected $table = 'hr_daily_overrides';

    protected $fillable = ['date', 'grace_minutes'];

    protected $casts = [
        'date' => 'date',
        'grace_minutes' => 'integer',
    ];
}
