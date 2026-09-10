<?php

namespace App\Modules\Crm\Models;

use App\Models\Concerns\BelongsToOrg;
use App\Modules\Crm\Database\Factories\DealFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * A won conversion for a lead: the interim conversion & revenue record behind BD Performance's
 * "Converted Leads", "Overall Revenue", and "Conversion Rate". Ownership is inherited from the
 * lead (`assigned_to`) — a deal has no independent owner. Adding this record was the approved way
 * to model conversion/revenue WITHOUT modifying the frozen Lead Status enum.
 *
 * @property Carbon|null $converted_at Cast to datetime; declared so static analysis applies the cast.
 */
#[Fillable(['lead_id', 'amount', 'converted_at'])]
class Deal extends Model
{
    use BelongsToOrg;
    protected $table = 'crm_deals';

    protected static function newFactory(): DealFactory
    {
        return DealFactory::new();
    }

    /** @use HasFactory<DealFactory> */
    use HasFactory, SoftDeletes;

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'converted_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Lead, $this> */
    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }
}
