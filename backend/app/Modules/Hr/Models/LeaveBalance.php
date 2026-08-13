<?php

namespace App\Modules\Hr\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class LeaveBalance extends Model
{
    use SoftDeletes;

    protected $table = 'hr_leave_balances';

    protected $fillable = [
        'employee_id',
        'leave_type_id',
        'year',
        'entitled_days',
        'used_days',
        // FIN-034 — written by LeaveAccrualService, not by HR's balance form.
        'accrued_days',
        'carried_days',
        'carried_expires_on',
        'accrued_through_month',
    ];

    protected $casts = [
        'year' => 'integer',
        // FIN-034 — FLOAT, not integer. This used to read "Whole days only — paid leave can't be a
        // half-day", which is the assumption half-day leave overturns. The columns have always been
        // decimal(5,1); the CAST was what rounded 0.5 to 0, so a half day of leave was approved,
        // deducted nothing, and left the employee's balance untouched.
        'entitled_days' => 'float',
        'used_days' => 'float',
        // FIN-034 — the two halves `entitled_days` is the sum of. Stored so the accrual engine can
        // recompute the total instead of incrementing it, which is what makes it re-run safe.
        'accrued_days' => 'float',
        'carried_days' => 'float',
        'carried_expires_on' => 'date',
        'accrued_through_month' => 'integer',
    ];

    /**
     * The employee this belongs to — `withTrashed()` because a SEPARATION SOFT-DELETES THEM.
     *
     * Without it the default scope resolves this to null the moment somebody leaves, and every
     * Resource that reads a name through it throws, taking the whole list endpoint down with it —
     * one 500 for the entire page because one row belonged to a leaver. Their history does not
     * stop being theirs when they go, and a record that cannot say whose it is is worse than one
     * that names someone who has left.
     */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class)->withTrashed();
    }

    public function leaveType(): BelongsTo
    {
        return $this->belongsTo(LeaveType::class);
    }
}
