<?php

namespace App\Modules\Hr\Models;

use App\Models\Concerns\BelongsToOrg;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * What payroll settings said from a given date — the fifth dated pricing input,
 * alongside salary, the statutory rates, allowances and the attendance policy.
 */
class PayrollSettingVersion extends Model
{
    use BelongsToOrg;
    protected $table = 'hr_payroll_setting_versions';

    protected $fillable = ['effective_from', 'payload', 'created_by'];

    protected $casts = [
        'effective_from' => 'date',
        'payload' => 'array',
    ];

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
