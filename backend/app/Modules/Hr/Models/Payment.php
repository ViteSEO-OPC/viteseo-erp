<?php

namespace App\Modules\Hr\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * One transfer to one employee for one payslip (FIN-015).
 *
 * This is the row that makes "settled" observable. Before it existed, a payslip flipped to
 * `disbursed` by hand and that was the end of the trail: no reference, no cleared date, and — the
 * expensive half — no way to record that a transfer bounced. A returned payment simply looked like
 * a successful one.
 *
 * THE BANK COLUMNS ARE A SNAPSHOT AND ARE ENCRYPTED. They are copied from the employee when the
 * batch is built, not joined at read time, because an employee who changes bank must not
 * retroactively change where last month's money went. They are encrypted because this table would
 * otherwise be a second plaintext copy of the most sensitive column in the system, which FIN-022
 * already flags on `hr_employees`. Consequence to know: they are opaque to SQL. Do not aggregate,
 * search or index on them.
 */
class Payment extends Model
{
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

    /**
     * The employee this belongs to — `withTrashed()` because a SEPARATION SOFT-DELETES THEM.
     *
     * Without it the default scope resolves this to null the moment somebody leaves, and every
     * Resource that reads a name through it throws, taking the whole list endpoint down with it —
     * one 500 for the entire page because one row belonged to a leaver. Their history does not
     * stop being theirs when they go, and a record that cannot say whose it is is worse than one
     * that names someone who has left.
     */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class)->withTrashed();
    }

    /**
     * The last four digits, which is all anyone needs to identify an account on screen.
     *
     * FIN-022's masking rule, applied here from the start rather than retrofitted: the resource
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
