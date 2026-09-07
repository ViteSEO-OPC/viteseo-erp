<?php

namespace App\Modules\Hr\Models;

use App\Models\Concerns\BelongsToOrg;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmployeeBenefit extends Model
{
    use BelongsToOrg;
    protected $table = 'hr_employee_benefits';

    protected $fillable = [
        'employee_id',
        'contribution_type',
        'enrolled',
    ];

    protected $casts = [
        'enrolled' => 'boolean',
    ];

    /** The employee this belongs to — `withTrashed()` because a SEPARATION SOFT-DELETES THEM. */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class)->withTrashed();
    }
}
