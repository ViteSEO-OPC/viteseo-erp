<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Models;

use App\Models\Concerns\BelongsToOrg;

use App\Models\Org;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A customer, vendor, or both — reused across invoices and bills.
 */
class Contact extends Model
{
    use BelongsToOrg;
    use SoftDeletes;

    protected $table = 'acc_contacts';

    protected $fillable = [
        'org_id',
        'source_org_id',
        'name',
        'type',
        'email',
        'phone',
        'tin',
        'address',
    ];

    public function org(): BelongsTo
    {
        return $this->belongsTo(Org::class);
    }
}
