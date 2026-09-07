<?php

namespace App\Modules\Hr\Models;

use App\Models\Concerns\BelongsToOrg;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Modules\Hr\Support\PayslipLines;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/** One correction added to an already-computed payslip. */
class PayslipAmendment extends Model
{
    use BelongsToOrg;
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

    /** Does this correction REPLACE a line the engine computed, rather than add one of its own? */
    public function isOverride(): bool
    {
        return $this->target_key !== null;
    }

    /** Has the engine changed its mind about this line since HR overruled it? */
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
