<?php

namespace App\Modules\Hr\Models;

use App\Models\Concerns\BelongsToOrg;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * What Company → Intern Allowance said from a given date. Read through
 * InternAllowanceService::asOf(), never directly — the payload is only meaningful once
 * InternAllowancePolicy has filled in any setting added after it was written.
 */
class InternAllowanceVersion extends Model
{
    use BelongsToOrg;

    protected $table = 'hr_intern_allowance_versions';

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
