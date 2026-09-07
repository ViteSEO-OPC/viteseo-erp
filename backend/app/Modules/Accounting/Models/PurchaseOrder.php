<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Models;

use App\Models\Concerns\BelongsToOrg;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A purchase order: a commitment to buy from a vendor. Posts no journal entry;
 * converting it to a bill records the accounting. Org-scoped, soft-deletable.
 */
class PurchaseOrder extends Model
{
    use BelongsToOrg;
    use SoftDeletes;

    public const OPEN = 'open';
    public const BILLED = 'billed';
    public const CANCELLED = 'cancelled';

    protected $table = 'acc_purchase_orders';

    protected $fillable = [
        'org_id',
        'contact_id',
        'po_no',
        'order_date',
        'expected_date',
        'description',
        'subtotal',
        'tax_rate',
        'tax_amount',
        'amount',
        'status',
        'bill_id',
    ];

    protected $casts = [
        'order_date' => 'date',
        'expected_date' => 'date',
        'subtotal' => 'decimal:2',
        'tax_rate' => 'decimal:2',
        'tax_amount' => 'decimal:2',
        'amount' => 'decimal:2',
    ];

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(PurchaseOrderLine::class);
    }
}
