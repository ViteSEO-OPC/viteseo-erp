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
 * A sales invoice (Accounts Receivable). Issuing it posts Dr A/R / Cr Income;
 * recording payment posts Dr Cash / Cr A/R.
 */
class Invoice extends Model
{
    use BelongsToOrg;
    use SoftDeletes;

    protected $table = 'acc_invoices';

    protected $fillable = [
        'org_id',
        'contact_id',
        'invoice_no',
        'customer_name',
        'issue_date',
        'due_date',
        'description',
        'amount',
        'amount_paid',
        'subtotal',
        'tax_rate',
        'tax_amount',
        'vat_class',
        'income_account_id',
        'status',
        'journal_entry_id',
        'payment_entry_id',
        'sent_at',
    ];

    protected $casts = [
        'issue_date' => 'date',
        'due_date' => 'date',
        'sent_at' => 'datetime',
        'amount' => 'decimal:2',
        'subtotal' => 'decimal:2',
        'tax_rate' => 'decimal:2',
        'tax_amount' => 'decimal:2',
    ];

    public function org(): BelongsTo
    {
        return $this->belongsTo(Org::class);
    }

    public function incomeAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'income_account_id');
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(InvoiceLine::class);
    }

    public function isPaid(): bool
    {
        return $this->status === 'paid';
    }
}
