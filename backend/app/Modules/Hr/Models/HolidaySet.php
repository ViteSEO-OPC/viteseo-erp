<?php

namespace App\Modules\Hr\Models;

use App\Models\Concerns\BelongsToOrg;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class HolidaySet extends Model
{
    use BelongsToOrg;
    use SoftDeletes;

    protected $table = 'hr_holiday_sets';

    protected $fillable = ['country_code', 'year', 'source', 'refreshed_at'];

    protected $casts = [
        'year' => 'integer',
        'refreshed_at' => 'datetime',
    ];

    public function holidays(): HasMany
    {
        return $this->hasMany(Holiday::class)->orderBy('date');
    }
}
