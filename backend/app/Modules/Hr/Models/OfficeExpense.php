<?php

namespace App\Modules\Hr\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class OfficeExpense extends Model
{
    use SoftDeletes;

    protected $table = 'hr_office_expenses';

    protected $fillable = [
        'category', 'amount', 'expense_date', 'description', 'vendor',
        'receipt_path', 'status', 'review_note', 'recorded_by', 'reviewed_by', 'reviewed_at',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'expense_date' => 'date',
        'reviewed_at' => 'datetime',
    ];

    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}
