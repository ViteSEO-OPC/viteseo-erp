<?php

namespace App\Modules\Hr\Models;

use App\Models\Concerns\BelongsToOrg;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/** One transfer to one employee for one payslip. */
class Payment extends Model
{
    use BelongsToOrg;
    use SoftDeletes;

    protected $table = 'hr_payments';

    /**
     * pending   in a batch, not yet confirmed by the bank
     * settled   confirmed — the ONLY state that means money actually moved
     * failed    the bank rejected it (bad or closed account)
     * returned  it left and came back
     */
    public const STATUSES = ['pending', 'settled', 'failed', 'returned'];

    /** Neither of these means the employee has their money; both need HR to act. */
    public const UNSUCCESSFUL = ['failed', 'returned'];

    protected $fillable = [
        'payment_batch_id', 'payslip_id', 'employee_id', 'amount', 'method',
        'bank_name', 'bank_account', 'bank_account_name', 'bank_account_type',
        'status', 'reference', 'settled_at', 'failure_reason',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'settled_at' => 'datetime',
        // See the class docblock — snapshot, encrypted, opaque to SQL.
        'bank_name' => 'encrypted',
        'bank_account' => 'encrypted',
        'bank_account_name' => 'encrypted',
    ];

    public function batch(): BelongsTo
    {
        return $this->belongsTo(PaymentBatch::class, 'payment_batch_id');
    }

    public function payslip(): BelongsTo
    {
        return $this->belongsTo(Payslip::class);
    }

    /** The employee this belongs to — `withTrashed()` because a SEPARATION SOFT-DELETES THEM. */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class)->withTrashed();
    }

    /**
     * The last four digits, which is all anyone needs to identify an account on screen.
     *
     * 's masking rule, applied here from the start rather than retrofitted: the resource
     * shows this and never the full number.
     */
    public function maskedAccount(): ?string
    {
        $account = $this->bank_account;

        if ($account === null || $account === '') {
            return null;
        }

        return strlen($account) <= 4 ? $account : '••••' . substr($account, -4);
    }
}
