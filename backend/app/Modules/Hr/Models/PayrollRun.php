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
        // Fixed when the run is created; never re-derived from live settings.
        'scheduled_pay_date',
        // 'regular' or 'thirteenth_month'; adds 'adjustment'.
        'type',
        // Why an off-cycle adjustment exists. Mandatory for that type.
        'reason',
        'total_gross', 'total_deductions', 'total_net',
        'employee_count', 'run_at', 'run_by',
        // A locked run can never be recomputed.
        'locked_at', 'locked_by',
        // Step 4b — the attendance policy as it stood when this run executed.
        'policy_snapshot',
        // An earlier period of the same tax year was amended, so this run's withholding was settled
        // against a year-to-date that has since moved. Cleared by recomputing it.
        'tax_stale_at', 'tax_stale_reason',
    ];

    protected $casts = [
        'cycle_start' => 'date',
        'cycle_end' => 'date',
        'scheduled_pay_date' => 'date',
        'run_at' => 'datetime',
        'locked_at' => 'datetime',
        'tax_stale_at' => 'datetime',
        'policy_snapshot' => 'array',
        'total_gross' => 'decimal:2',
        'total_deductions' => 'decimal:2',
        'total_net' => 'decimal:2',
    ];

    /**
     * How many payslips this run REFUSED to recompute because they were already disbursed
     * . Set by `PayrollService::run()`, never stored.
     */
    public ?int $skippedDisbursedCount = null;

    /**
     * WHAT THE RECOMPUTE FOUND UNDERNEATH THE CORRECTIONS — one sentence per correction the engine
     * has since disagreed with, or whose line no longer exists.
     *
     * An override is absolute and still wins, so none of these changed a figure. That is exactly
     * why they have to be said: nothing looks wrong, and only the person who made the correction
     * can decide whether it still belongs. Set by `PayrollService::run()`, never stored.
     *
     * @var list<string>|null
     */
    public ?array $overrideNotes = null;

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

    /** Was this run's withholding computed against a year-to-date that has since been amended? */
    public function isTaxStale(): bool
    {
        return $this->tax_stale_at !== null;
    }
}
