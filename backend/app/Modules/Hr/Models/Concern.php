<?php

namespace App\Modules\Hr\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Concern extends Model
{
    use SoftDeletes;

    protected $table = 'hr_concerns';

    protected $fillable = [
        'employee_id', 'reference_no', 'category', 'description', 'reported_person',
        'attachments', 'is_anonymous', 'status', 'submitted_at', 'resolved_at',
        'cancelled_at', 'cancelled_by', 'cancel_reason',
    ];

    protected $casts = [
        'is_anonymous' => 'boolean',
        'attachments' => 'array',
        'submitted_at' => 'datetime',
        'resolved_at' => 'datetime',
        'cancelled_at' => 'datetime',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function meetings(): HasMany
    {
        return $this->hasMany(Meeting::class);
    }

    /** Who withdrew this request, when it was cancelled rather than decided. */
    public function canceller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }
}
