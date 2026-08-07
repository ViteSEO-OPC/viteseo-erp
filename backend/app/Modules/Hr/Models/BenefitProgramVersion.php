<?php

namespace App\Modules\Hr\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * What an allowance was worth from a given date — Slice B11.
 *
 * The third and last of the dated pricing inputs, matching salary (`hr_salary_histories`) and the
 * statutory rates (`hr_contribution_versions`). Without it, re-running an old cycle priced its
 * allowances at today's figure.
 *
 * `effective_from` only, no end date: the version in force is the latest row on or before the
 * cycle end. Two date columns can leave a gap (a period with no amount) or an overlap (two), and
 * one start date makes both impossible.
 */
class BenefitProgramVersion extends Model
{
    protected $table = 'hr_benefit_program_versions';

    protected $fillable = [
        'benefit_program_id', 'effective_from', 'amount', 'frequency',
        'tax_treatment', 'deminimis_ceiling', 'ceiling_period', 'created_by',
        // Slice B19 — whether this allowance counted toward the statutory contribution base.
        'contributable',
    ];

    protected $casts = [
        'effective_from' => 'date',
        'amount' => 'decimal:2',
        'deminimis_ceiling' => 'decimal:2',
        'contributable' => 'boolean',
    ];

    public function program(): BelongsTo
    {
        return $this->belongsTo(BenefitProgram::class, 'benefit_program_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
