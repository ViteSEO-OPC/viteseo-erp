<?php

namespace App\Modules\Hr\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * One payout of one payroll run (FIN-015).
 *
 * The unit a bank is actually paid in: a file of transfers, generated once, downloaded once, and
 * reconciled back as a whole. It exists so a disbursement has an identity, a total that can be
 * checked against the run, and a record of when it was sent.
 *
 * `total_amount` and `payment_count` are maintained by `PaymentService`, never written directly —
 * they are a cache of the payment rows, and the reconciliation check compares them against the
 * run's own `total_net`.
 */
class PaymentBatch extends Model
{
    use SoftDeletes;

    protected $table = 'hr_payment_batches';

    /** How the batch leaves the company. One method per batch — a bank file is per method. */
    public const METHODS = ['bank_transfer', 'cash', 'check'];

    /**
     * pending   built, not yet sent to the bank
     * exported  the bank file has been generated and downloaded
     * settled   every payment in it confirmed
     * partial   some settled, some failed or still outstanding
     */
    public const STATUSES = ['pending', 'exported', 'settled', 'partial'];

    protected $fillable = [
        'payroll_run_id', 'reference', 'method', 'status',
        'total_amount', 'payment_count',
        'exported_at', 'exported_by', 'created_by', 'notes',
    ];

    protected $casts = [
        'total_amount' => 'decimal:2',
        'payment_count' => 'integer',
        'exported_at' => 'datetime',
    ];

    public function payrollRun(): BelongsTo
    {
        return $this->belongsTo(PayrollRun::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function exporter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'exported_by');
    }

    /**
     * A batch that has been sent to the bank cannot be rebuilt.
     *
     * Once the file is downloaded the company has to assume it was uploaded — regenerating a batch
     * from changed payslips after that point is how an employee gets paid twice.
     */
    public function isSent(): bool
    {
        return $this->exported_at !== null;
    }
}
