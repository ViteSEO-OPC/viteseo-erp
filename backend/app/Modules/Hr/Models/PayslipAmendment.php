<?php

namespace App\Modules\Hr\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Modules\Hr\Support\PayslipLines;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

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
        // An EDIT to a line already on the payslip, rather than a line added underneath it:
        // which line, what HR says it is, what the engine said, and the wording HR gave it.
        'target_key', 'override_amount', 'original_amount', 'edited_against', 'label_override',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'tax' => 'decimal:2',
        'override_amount' => 'decimal:2',
        'original_amount' => 'decimal:2',
        'edited_against' => 'decimal:2',
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

    /**
     * Does this correction REPLACE a line the engine computed, rather than add one of its own?
     *
     * The distinction runs through everything downstream: an override is printed ON its line with
     * the engine's figure struck through beside it, while an addition is printed as a line of its
     * own. Confusing the two counts the same money twice.
     */
    public function isOverride(): bool
    {
        return $this->target_key !== null;
    }

    /**
     * Has the engine changed its mind about this line since HR overruled it?
     *
     * The override still wins — it is an absolute figure and a recompute does not get to re-decide
     * it. This is what lets a run SAY SO, so HR can drop a correction that has outlived the problem
     * it was fixing rather than leave it quietly suppressing a figure nobody has looked at.
     */
    public function hasDrifted(): bool
    {
        if ($this->edited_against === null || $this->original_amount === null) {
            return false;
        }

        return round((float) $this->edited_against, 2) !== round((float) $this->original_amount, 2);
    }

    /** The line this corrected, in the words the payslip prints — HR's wording if they gave one. */
    public function targetLabel(): ?string
    {
        if ($this->target_key === null) {
            return null;
        }

        return $this->label_override
            ?? PayslipLines::labelFor($this->target_key)
            // A benefit share or a loan repayment names itself; the key carries only its slug, so
            // the readable name comes back from the payslip's own breakdown, not from here.
            ?? Str::headline(Str::afterLast($this->target_key, ':'));
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
