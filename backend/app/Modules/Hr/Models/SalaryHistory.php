<?php

namespace App\Modules\Hr\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SalaryHistory extends Model
{
    protected $table = 'hr_salary_histories';

    protected $fillable = [
        'employee_id',
        'base_salary',
        'effective_date',
        'reason',
        'change_type',
        'changed_by',
    ];

    protected $casts = [
        'base_salary' => 'decimal:2',
        'effective_date' => 'date',
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

    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }
}
