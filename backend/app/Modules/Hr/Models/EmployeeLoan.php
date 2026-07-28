<?php

namespace App\Modules\Hr\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A loan or cash advance repaid out of payroll (Slice 11b).
 *
 * There is deliberately NO `balance` column — see outstanding(). A stored balance decremented
 * per run would double-count on a re-run; it is derived from the payment ledger instead.
 */
class EmployeeLoan extends Model
{
    use SoftDeletes;

    protected $table = 'hr_employee_loans';

    public const TYPES = ['loan', 'cash_advance', 'other'];

    public const STATUSES = ['active', 'settled', 'cancelled', 'on_hold'];

    protected $fillable = [
        'employee_id', 'type', 'reference', 'principal', 'per_period_amount',
        'start_date', 'status', 'notes', 'created_by',
    ];

    protected $casts = [
        'principal' => 'decimal:2',
        'per_period_amount' => 'decimal:2',
        'start_date' => 'date',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(LoanPayment::class, 'loan_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** Total repaid so far, from the ledger. */
    public function paid(): float
    {
        return round((float) $this->payments()->sum('amount'), 2);
    }

    /** What is still owed. Derived, so it can never drift from the payments. */
    public function outstanding(): float
    {
        return round(max(0, (float) $this->principal - $this->paid()), 2);
    }

    /** Only an active loan that still owes something is collected from. */
    public function isCollectible(): bool
    {
        return $this->status === 'active' && $this->outstanding() > 0;
    }
}
