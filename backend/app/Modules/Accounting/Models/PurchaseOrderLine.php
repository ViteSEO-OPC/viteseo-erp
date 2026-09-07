<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A line on a purchase order (copied into the bill on conversion). */
class PurchaseOrderLine extends Model
{
    protected $table = 'acc_purchase_order_lines';

    protected $fillable = [
        'purchase_order_id',
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

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }
}
