<?php

namespace App\Modules\Hr\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class PayrollInquiry extends Model
{
    use SoftDeletes;

    protected $table = 'hr_payroll_inquiries';

    protected $fillable = [
        'employee_id', 'payslip_id', 'subject', 'topic', 'message', 'attachment_path',
        'priority', 'status', 'resolution_note', 'resolution_attachments', 'resolved_by', 'resolved_at',
        'cancelled_at', 'cancelled_by', 'cancel_reason',
    ];

    protected $casts = [
        'resolved_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'resolution_attachments' => 'array',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function payslip(): BelongsTo
    {
        return $this->belongsTo(Payslip::class);
    }

    public function resolver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }

    /** Who withdrew this request, when it was cancelled rather than decided. */
    public function canceller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }
}
