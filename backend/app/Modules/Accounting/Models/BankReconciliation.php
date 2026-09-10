<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Models;

use App\Models\Concerns\BelongsToOrg;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A completed bank reconciliation: the statement it was matched against plus the
 * balances that reconciled (beginning + cleared = statement). Cleared journal
 * lines reference this record via acc_journal_lines.bank_reconciliation_id.
 */
class BankReconciliation extends Model
{
    use BelongsToOrg;

    protected $table = 'acc_bank_reconciliations';

    protected $fillable = [
        'org_id',
        'account_id',
        'statement_date',
        'statement_balance',
        'beginning_balance',
        'cleared_balance',
        'status',
        'reconciled_by',
    ];

    protected $casts = [
        'statement_date' => 'date',
        'statement_balance' => 'decimal:2',
        'beginning_balance' => 'decimal:2',
        'cleared_balance' => 'decimal:2',
    ];

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'account_id');
    }
}
