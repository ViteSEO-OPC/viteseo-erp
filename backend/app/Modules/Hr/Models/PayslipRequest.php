<?php

namespace App\Modules\Hr\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class PayslipRequest extends Model
{
    use SoftDeletes;

    protected $table = 'hr_payslip_requests';

    protected $fillable = [
        'employee_id', 'payslip_id', 'reason', 'status',
        'review_note', 'reviewed_by', 'reviewed_at',
    ];

    protected $casts = [
        'reviewed_at' => 'datetime',
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

    public function payslip(): BelongsTo
    {
        return $this->belongsTo(Payslip::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}
