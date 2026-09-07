<?php

namespace App\Modules\Hr\Models;

use App\Models\Concerns\BelongsToOrg;
use Illuminate\Database\Eloquent\Model;

class SharedLeaveAllotment extends Model
{
    use BelongsToOrg;
    protected $table = 'hr_shared_leave_allotments';

    protected $fillable = [
        'employment_status',
        'days_per_year',
    ];

    protected $casts = [
        'days_per_year' => 'integer',
    ];
}
