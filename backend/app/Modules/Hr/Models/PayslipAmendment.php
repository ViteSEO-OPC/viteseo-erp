<?php

namespace App\Modules\Hr\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * One correction added to an already-computed payslip.
 *
 * The LEDGER behind `Payslip::adjustment_pay`, not a mirror of it: that column is the running total,
 * these rows are what it totals. The distinction matters because recomputing a period rewrites every
 * earnings column from attendance — without a record the recompute does not own, a correction would
 * vanish the next time someone re-ran the period that carried it.
 *
 * `tax` is stored rather than re-derived for the same reason. It was computed marginally on the
 * year's earnings as they stood when the correction was raised; re-deciding it against a later YTD
 * would change what was withheld on money that may already have been paid.
 *
 * `kind` says WHAT the row is, and `amount` carries the direction in its sign — see the kind
 * migration. A `deduction` never touches gross or taxable pay, which is the whole reason it cannot
 * simply be a negative `pay`.
 */
class PayslipAmendment extends Model
{
    use SoftDeletes;

    protected $table = 'hr_payslip_amendments';

    protected $fillable = [
        'payslip_id', 'kind', 'amount', 'tax', 'reason', 'note', 'issued_on', 'created_by',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'tax' => 'decimal:2',
        'issued_on' => 'date',
    ];

    public function payslip(): BelongsTo
    {
        return $this->belongsTo(Payslip::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** What this correction added to net pay — the amount less what was withheld on it. */
    public function net(): float
    {
        return round((float) $this->amount - (float) $this->tax, 2);
    }

    /** Money taken off net without touching gross — a deduction the payslip had missed. */
    public function isDeduction(): bool
    {
        return $this->kind === 'deduction';
    }

    /**
     * How this reads on a document, in the words the payslip uses.
     *
     * Named here rather than in each surface because the PDF, the React payslip and the run history
     * all print it, and three hand-written label lists would drift the first time a kind was added.
     */
    public function kindLabel(): string
    {
        return match ($this->kind) {
            'pay_reversal' => 'Pay reversed',
            'deduction' => 'Deduction added',
            default => 'Additional pay',
        };
    }
}
