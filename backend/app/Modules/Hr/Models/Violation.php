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

    /**
     * The employee this belongs to — `withTrashed()` because a SEPARATION SOFT-DELETES THEM.
     *
     * Without it the default scope resolves this to null the moment somebody leaves, and every
     * Resource that reads a name through it throws, taking the whole list endpoint down with it —
     * one 500 for the entire page because one row belonged to a leaver. Their history does not
     * stop being theirs when they go, and a record that cannot say whose it is is worse than one
     * that names someone who has left.
     */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class)->withTrashed();
    }
}
