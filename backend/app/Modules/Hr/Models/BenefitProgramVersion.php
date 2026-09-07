<?php

namespace App\Modules\Hr\Models;

use App\Models\Concerns\BelongsToOrg;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** What an allowance was worth from a given date. */
class BenefitProgramVersion extends Model
{
    use BelongsToOrg;
    protected $table = 'hr_benefit_program_versions';

    protected $fillable = [
        'benefit_program_id', 'effective_from', 'amount', 'frequency',
        'tax_treatment', 'deminimis_ceiling', 'ceiling_period', 'created_by',
        // Whether this allowance counted toward the statutory contribution base.
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
