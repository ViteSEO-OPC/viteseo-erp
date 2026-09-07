<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A line in a recurring-invoice template (copied into each generated invoice). */
class RecurringInvoiceLine extends Model
{
    protected $table = 'acc_recurring_invoice_lines';

    protected $fillable = [
        'recurring_invoice_id',
        'description',
        'quantity',
        'unit_price',
        'account_id',
    ];

    protected $casts = [
        'quantity' => 'decimal:2',
        'unit_price' => 'decimal:2',
    ];

    public function recurringInvoice(): BelongsTo
    {
        return $this->belongsTo(RecurringInvoice::class);
    }
}
