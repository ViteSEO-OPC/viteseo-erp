<?php

namespace App\Modules\Hr\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One Monthly Salary Credit band of a bracket-based contribution (Slice B3b).
 *
 * The band runs from `min_salary` up to wherever the next bracket starts — there is no `max`,
 * which is what makes gaps and overlaps impossible to express.
 */
class ContributionBracket extends Model
{
    protected $table = 'hr_contribution_brackets';

    protected $fillable = ['contribution_version_id', 'min_salary', 'msc', 'employer_add_on'];

    protected $casts = [
        'min_salary' => 'decimal:2',
        'msc' => 'decimal:2',
        // Slice B8 — the employer-only EC amount printed for this band. Null ⇒ fall back to the
        // version's `fixed_employer_add_on` / `add_on_min_salary` rule.
        'employer_add_on' => 'decimal:2',
    ];

    public function version(): BelongsTo
    {
        return $this->belongsTo(ContributionVersion::class, 'contribution_version_id');
    }
}
