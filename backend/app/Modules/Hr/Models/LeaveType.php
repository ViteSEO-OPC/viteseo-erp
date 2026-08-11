<?php

namespace App\Modules\Hr\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class LeaveType extends Model
{
    use SoftDeletes;

    protected $table = 'hr_leave_types';

    protected $fillable = [
        'name', 'code', 'days_per_year', 'is_paid', 'is_unlimited', 'color', 'description',
        'allow_backdated', 'backdate_limit_days',
        // FIN-034 — accrual. 'none' (default, hand-managed as before) | 'monthly' | 'annual'.
        // The cap and expiry are company policy, so neither has a shipped figure.
        'accrual_mode', 'prorate_first_year', 'carry_over_cap_days', 'carry_over_expiry_months',
    ];

    protected $casts = [
        // Paid leave is whole-day only (half-days are a separate unpaid request).
        // FLOAT, not integer — the column is decimal(5,1) and monthly accrual divides it by 12,
        // so an entitlement of 12.5 days has to survive the round trip (cf. FIN-034 elsewhere).
        'days_per_year' => 'float',
        'prorate_first_year' => 'boolean',
        'carry_over_cap_days' => 'float',
        'carry_over_expiry_months' => 'integer',
        'is_paid' => 'boolean',
        'is_unlimited' => 'boolean',
        'allow_backdated' => 'boolean',
        'backdate_limit_days' => 'integer',
    ];

    public function allotments(): HasMany
    {
        return $this->hasMany(LeaveTypeAllotment::class);
    }
}
