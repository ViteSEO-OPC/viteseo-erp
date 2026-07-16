<?php

namespace App\Modules\Hr\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class LeaveType extends Model
{
    use SoftDeletes;

    protected $table = 'hr_leave_types';

    protected $fillable = ['name', 'code', 'days_per_year', 'is_paid', 'color', 'allow_backdated', 'backdate_limit_days'];

    protected $casts = [
        // Paid leave is whole-day only (half-days are a separate unpaid request).
        'days_per_year' => 'integer',
        'is_paid' => 'boolean',
        'allow_backdated' => 'boolean',
        'backdate_limit_days' => 'integer',
    ];

    public function allotments(): HasMany
    {
        return $this->hasMany(LeaveTypeAllotment::class);
    }
}
