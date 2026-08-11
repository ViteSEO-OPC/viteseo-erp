<?php

namespace App\Modules\Hr\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class EarlyOutRequest extends Model
{
    use SoftDeletes;

    protected $table = 'hr_early_out_requests';

    protected $fillable = [
        'employee_id',
        'work_date',
        'early_out_time',
        'reason',
        'status',
        'review_note',
        'cancelled_at',
        'cancelled_by',
        'cancel_reason',
        'reviewed_by',
        'reviewed_at',
    ];

    protected $casts = [
        'cancelled_at' => 'datetime',
        'work_date' => 'date',
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

    /** Who withdrew this request, when it was cancelled rather than decided. */
    public function canceller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }
}
