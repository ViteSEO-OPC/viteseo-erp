<?php

namespace App\Modules\Hr\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Reimbursement extends Model
{
    use SoftDeletes;

    protected $table = 'hr_reimbursements';

    protected $fillable = [
        'employee_id',
        'category',
        'amount',
        'expense_date',
        'description',
        'receipt_path',
        'status',
        'payroll_run_id',
        'reviewed_at',
        'reviewed_by',
        'review_note',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'expense_date' => 'date',
        'reviewed_at' => 'datetime',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}
