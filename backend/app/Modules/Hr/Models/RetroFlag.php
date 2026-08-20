<?php

namespace App\Modules\Hr\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/** An outstanding payroll difference caused by a decision made after the period was paid. */
class RetroFlag extends Model
{
    protected $table = 'hr_retro_flags';

    /** The employee was underpaid — settle it with an adjustment run. */
    public const OWED = 'owed';

    /** The employee was overpaid — recovery is a wage deduction and needs their consent. */
    public const OVERPAID = 'overpaid';

    public const DIRECTIONS = [self::OWED, self::OVERPAID];

    protected $fillable = [
        'employee_id', 'flaggable_type', 'flaggable_id', 'payroll_run_id',
        'direction', 'reason', 'resolved_at', 'resolved_by', 'resolution_note',
    ];

    protected $casts = ['resolved_at' => 'datetime'];

    public function flaggable(): MorphTo
    {
        return $this->morphTo();
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'employee_id');
    }

    public function payrollRun(): BelongsTo
    {
        return $this->belongsTo(PayrollRun::class, 'payroll_run_id');
    }

    public function resolver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }

    public function isOutstanding(): bool
    {
        return $this->resolved_at === null;
    }
}
