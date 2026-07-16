<?php

namespace App\Modules\Hr\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class LeaveType extends Model
{
    use SoftDeletes;

    protected $table = 'hr_leave_types';

    protected $fillable = ['name', 'code', 'days_per_year', 'is_paid', 'color'];

    protected $casts = [
        'days_per_year' => 'decimal:1',
        'is_paid' => 'boolean',
    ];

    public function allotments(): HasMany
    {
        return $this->hasMany(LeaveTypeAllotment::class);
    }
}
