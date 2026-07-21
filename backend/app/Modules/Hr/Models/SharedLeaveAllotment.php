<?php

namespace App\Modules\Hr\Models;

use Illuminate\Database\Eloquent\Model;

class SharedLeaveAllotment extends Model
{
    protected $table = 'hr_shared_leave_allotments';

    protected $fillable = [
        'employment_status',
        'days_per_year',
    ];

    protected $casts = [
        'days_per_year' => 'integer',
    ];
}
