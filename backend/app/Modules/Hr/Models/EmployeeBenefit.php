<?php

namespace App\Modules\Hr\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmployeeBenefit extends Model
{
    protected $table = 'hr_employee_benefits';

    protected $fillable = [
        'employee_id',
        'contribution_type',
        'enrolled',
    ];

    protected $casts = [
        'enrolled' => 'boolean',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
