<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Models;

use App\Models\Concerns\BelongsToOrg;

use App\Models\Org;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A supplier bill (Accounts Payable). Recording it posts Dr Expense / Cr A/P;
 * paying it posts Dr A/P / Cr Cash.
 */
class Bill extends Model
{
    use BelongsToOrg;
    use SoftDeletes;

    protected $table = 'acc_bills';

    protected $fillable = [
        'org_id',
        'contact_id',
        'bill_no',
        'vendor_name',
        'issue_date',
        'due_date',
        'description',
        'amount',
        'amount_paid',
        'subtotal',
        'tax_rate',
        'tax_amount',
        'vat_class',
        'expense_account_id',
        'status',
        'journal_entry_id',
        'payment_entry_id',
    ];

    protected $casts = [
        'issue_date' => 'date',
        'due_date' => 'date',
        'amount' => 'decimal:2',
        'subtotal' => 'decimal:2',
        'tax_rate' => 'decimal:2',
        'tax_amount' => 'decimal:2',
    ];

    public function org(): BelongsTo
    {
        return $this->belongsTo(Org::class);
    }

    public function expenseAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'expense_account_id');
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(BillLine::class);
    }

    public function isPaid(): bool
    {
        return $this->status === 'paid';
    }
}
