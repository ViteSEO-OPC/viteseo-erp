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

    /**
     * Slice B15 — the two states before `active` are what make the deduction lawful.
     *
     *   requested              the employee asked; HR has not decided
     *   rejected               HR said no
     *   pending_authorization  approved or issued, and waiting on the EMPLOYEE's written consent
     *   active                 authorized; payroll may collect
     *
     * `collectForPeriod()` reads `active` only, so an unauthorized loan cannot be collected by
     * construction rather than by a check somebody has to remember to write.
     */
    public const STATUSES = [
        'requested', 'rejected', 'pending_authorization',
        'active', 'settled', 'cancelled', 'on_hold',
    ];

    protected $fillable = [
        'employee_id', 'benefit_program_id', 'type', 'reference', 'principal', 'per_period_amount',
        'start_date', 'status', 'notes', 'created_by',
        // FIN-032 — diminishing-balance interest, a one-off fee, and collection order. All default
        // to zero / 100, so an existing loan is unchanged and a 0% advance stays expressible.
        'interest_rate', 'interest_period', 'fees', 'priority',
        // Slice B15 — origination and consent.
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

    /** The policy this loan was issued under (Slice B15). Null for an ad-hoc arrangement. */
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

    /** Principal repaid — what actually reduces the balance (FIN-032). */
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

    /**
     * What is still owed of the PRINCIPAL. Derived, so it can never drift from the payments.
     *
     * FIN-032 — this reads `principal_amount`, not `amount`, and that distinction is what keeps the
     * loan honest. Interest is charged ON this balance; if it also reduced it, a loan would pay itself
     * off out of its own interest and settle early for less than was borrowed.
     */
    public function outstanding(): float
    {
        return round(max(0, (float) $this->principal - $this->paidPrincipal()), 2);
    }

    /** Any part of the one-off fee not yet collected. */
    public function outstandingFees(): float
    {
        return round(max(0, (float) $this->fees - $this->paidFees()), 2);
    }

    /**
     * The interest rate for ONE pay period, as a decimal fraction (FIN-032).
     *
     * Diminishing balance, so the caller multiplies this by the balance still owed. The stored rate is
     * a percentage quoted either per year or per month — `interest_period` says which, because
     * companies use both and reading "2%" as annual when they meant monthly is a twelve-fold error.
     *
     * A monthly rate is converted through the year rather than by dividing by the number of months,
     * so a semi-monthly employee is charged half a month's interest per payday instead of a whole one.
     */
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
     * FIN-032 — an uncollected FEE keeps a loan collectible even once the principal is repaid, because
     * the fee is genuinely still owed and the alternative is writing it off silently.
     */
    public function isCollectible(): bool
    {
        return $this->status === 'active' && ($this->outstanding() > 0 || $this->outstandingFees() > 0);
    }
}
