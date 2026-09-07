<?php

namespace App\Modules\Hr\Models;

use App\Models\Concerns\BelongsToOrg;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One band of a graduated tax table.
 *
 * `tax = base_tax + rate_on_excess × (income − min_annual)`, which is how the BIR prints it, so a
 * row can be checked against the published table by eye.
 */
class TaxBracket extends Model
{
    use BelongsToOrg;
    use HasFactory;

    protected $table = 'hr_tax_brackets';

    protected $fillable = ['tax_version_id', 'min_annual', 'base_tax', 'rate_on_excess'];

    protected $casts = [
        'min_annual' => 'decimal:2',
        'base_tax' => 'decimal:2',
        'rate_on_excess' => 'decimal:4',
    ];

    /** @return BelongsTo<TaxVersion, $this> */
    public function version(): BelongsTo
    {
        return $this->belongsTo(TaxVersion::class, 'tax_version_id');
    }
}
