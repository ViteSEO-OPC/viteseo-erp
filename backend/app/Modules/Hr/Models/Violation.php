<?php

namespace App\Modules\Hr\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Violation extends Model
{
    protected $table = 'hr_violations';

    protected $fillable = [
        'employee_id',
        'type',
        'severity',
        'work_date',
        'details',
        'resolved',
    ];

    protected $casts = [
        'work_date' => 'date',
        'resolved' => 'boolean',
    ];

    /** Severity ordering so we can pick an employee's worst flag. */
    public const SEVERITY_RANK = [
        'green' => 1,
        'yellow' => 2,
        'orange' => 3,
        'red' => 4,
    ];

    /** The employee this belongs to — `withTrashed()` because a SEPARATION SOFT-DELETES THEM. */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class)->withTrashed();
    }
}
