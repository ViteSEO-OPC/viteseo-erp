<?php

namespace App\Modules\Hr\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A loan or cash advance repaid out of payroll.
 *
 * There is deliberately NO `balance` column — see outstanding(). A stored balance decremented
 * per run would double-count on a re-run; it is derived from the payment ledger instead.
 */
class EmployeeLoan extends Model
{
    use SoftDeletes;

    protected $table = 'hr_employee_loans';

    public const TYPES = ['loan', 'cash_advance', 'other'];

    /** The two states before `active` are what make the deduction lawful. */
    public const STATUSES = [
        'requested', 'rejected', 'pending_authorization',
        'active', 'settled', 'cancelled', 'on_hold',
    ];

    protected $fillable = [
        'employee_id', 'benefit_program_id', 'type', 'reference', 'principal', 'per_period_amount',
        'start_date', 'status', 'notes', 'created_by',
        // diminishing-balance interest, a one-off fee, and collection order. All default
        // to zero / 100, so an existing loan is unchanged and a 0% advance stays expressible.
        'interest_rate', 'interest_period', 'fees', 'priority',
        // Origination and consent.
        'requested_at', 'approved_by', 'approved_at', 'decision_note',
        'authorized_at', 'authorized_by',
    ];

    protected $casts = [
        'requested_at' => 'datetime',
        'approved_at' => 'datetime',
        'authorized_at' => 'datetime',
        'principal' => 'decimal:2',
        'per_period_amount' => 'decimal:2',
        // FLOAT, not decimal:2 — a rate needs four places (10.5% must not become 10.50 rounded to
        // two on a fraction), and it is arithmetic, not money.
        'interest_rate' => 'float',
        'fees' => 'decimal:2',
        'priority' => 'integer',
        'start_date' => 'date',
    ];

    /** The employee this belongs to — `withTrashed()` because a SEPARATION SOFT-DELETES THEM. */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class)->withTrashed();
    }

    /** The policy this loan was issued under. Null for an ad-hoc arrangement. */
    public function policy(): BelongsTo
    {
        return $this->belongsTo(BenefitProgram::class, 'benefit_program_id');
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

    /** Principal repaid — what actually reduces the balance. */
    public function paidPrincipal(): float
    {
        return round((float) $this->payments()->sum('principal_amount'), 2);
    }

    /** Interest charged and collected to date. Never reduces the balance. */
    public function paidInterest(): float
    {
        return round((float) $this->payments()->sum('interest_amount'), 2);
    }

    public function paidFees(): float
    {
        return round((float) $this->payments()->sum('fee_amount'), 2);
    }

    /** What is still owed of the PRINCIPAL. Derived, so it can never drift from the payments. */
    public function outstanding(): float
    {
        return round(max(0, (float) $this->principal - $this->paidPrincipal()), 2);
    }

    /** Any part of the one-off fee not yet collected. */
    public function outstandingFees(): float
    {
        return round(max(0, (float) $this->fees - $this->paidFees()), 2);
    }

    /** The interest rate for ONE pay period, as a decimal fraction. */
    public function periodInterestRate(int $periodsPerYear): float
    {
        $rate = (float) $this->interest_rate / 100;

        if ($rate <= 0 || $periodsPerYear <= 0) {
            return 0.0;
        }

        $annual = $this->interest_period === 'monthly' ? $rate * 12 : $rate;

        return $annual / $periodsPerYear;
    }

    /** This period's interest on the balance still owed. */
    public function periodInterest(int $periodsPerYear, ?float $balance = null): float
    {
        return round(($balance ?? $this->outstanding()) * $this->periodInterestRate($periodsPerYear), 2);
    }

    /**
     * Only an active loan that still owes something is collected from.
     *
     * An uncollected FEE keeps a loan collectible even once the principal is repaid, because
     * the fee is genuinely still owed and the alternative is writing it off silently.
     */
    public function isCollectible(): bool
    {
        return $this->status === 'active' && ($this->outstanding() > 0 || $this->outstandingFees() > 0);
    }
}
