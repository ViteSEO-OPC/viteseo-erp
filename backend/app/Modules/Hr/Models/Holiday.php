<?php

namespace App\Modules\Hr\Models;

use App\Models\Concerns\BelongsToOrg;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Holiday extends Model
{
    use BelongsToOrg;
    use SoftDeletes;

    protected $table = 'hr_holidays';

    protected $fillable = [
        'holiday_set_id', 'name', 'date', 'type', 'source', 'needs_review',
    ];

    protected $casts = [
        'date' => 'date',
        'needs_review' => 'boolean',
    ];

    public function set(): BelongsTo
    {
        return $this->belongsTo(HolidaySet::class, 'holiday_set_id');
    }
}
