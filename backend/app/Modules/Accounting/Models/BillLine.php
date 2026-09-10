<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BillLine extends Model
{
    protected $table = 'acc_bill_lines';

    protected $fillable = [
        'bill_id',
        'description',
        'quantity',
        'unit_price',
        'account_id',
        'line_total',
    ];

    protected $casts = [
        'quantity' => 'decimal:2',
        'unit_price' => 'decimal:2',
        'line_total' => 'decimal:2',
    ];

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'account_id');
    }
}
