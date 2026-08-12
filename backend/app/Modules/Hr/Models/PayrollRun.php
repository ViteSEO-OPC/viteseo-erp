<?php

namespace App\Modules\Hr\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class PayrollRun extends Model
{
    use SoftDeletes;

    protected $table = 'hr_payroll_runs';

    protected $fillable = [
        'cycle_start', 'cycle_end', 'frequency', 'status',
        // FIN-036 — fixed when the run is created; never re-derived from live settings.
        'scheduled_pay_date',
        // Slice B17 — 'regular' or 'thirteenth_month'; FIN-010 adds 'adjustment'.
        'type',
        // FIN-010 — why an off-cycle adjustment exists. Mandatory for that type.
        'reason',
        'total_gross', 'total_deductions', 'total_net',
        'employee_count', 'run_at', 'run_by',
        // Slice 5 — a locked run can never be recomputed.
        'locked_at', 'locked_by',
        // Step 4b — the attendance policy as it stood when this run executed.
        'policy_snapshot',
    ];

    protected $casts = [
        'cycle_start' => 'date',
        'cycle_end' => 'date',
        'scheduled_pay_date' => 'date',
        'run_at' => 'datetime',
        'locked_at' => 'datetime',
        'policy_snapshot' => 'array',
        'total_gross' => 'decimal:2',
        'total_deductions' => 'decimal:2',
        'total_net' => 'decimal:2',
    ];

    /**
     * How many payslips this run REFUSED to recompute because they were already disbursed
     * (FIN-051). Set by `PayrollService::run()`, never stored.
     *
     * A real declared property rather than a magic attribute: Eloquent's `__set` would otherwise
     * file it among the model's attributes and the next `save()` would try to write a column that
     * does not exist. Declaring it keeps it off the attribute bag entirely.
     */
    public ?int $skippedDisbursedCount = null;

    public function payslips(): HasMany
    {
        return $this->hasMany(Payslip::class);
    }

    public function runner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'run_by');
    }

    public function locker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'locked_by');
    }

    public function isLocked(): bool
    {
        return $this->locked_at !== null;
    }
}
