<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Models;

use App\Models\Concerns\BelongsToOrg;
use App\Models\Org;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A recurring-invoice schedule: which customer, how often, and the line template
 * used to generate each period's invoice. Org-scoped.
 */
class RecurringInvoice extends Model
{
    use BelongsToOrg;

    public const ACTIVE = 'active';
    public const PAUSED = 'paused';
    public const ENDED = 'ended';

    protected $table = 'acc_recurring_invoices';

    protected $fillable = [
        'org_id',
        'contact_id',
        'description',
        'frequency',
        'tax_rate',
        'vat_class',
        'due_days',
        'start_date',
        'next_run_date',
        'end_date',
        'status',
        'last_generated_at',
        'occurrences',
    ];

    protected $casts = [
        'tax_rate' => 'decimal:2',
        'due_days' => 'integer',
        'start_date' => 'date',
        'next_run_date' => 'date',
        'end_date' => 'date',
        'last_generated_at' => 'datetime',
        'occurrences' => 'integer',
    ];

    public function org(): BelongsTo
    {
        return $this->belongsTo(Org::class);
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(RecurringInvoiceLine::class);
    }
}
