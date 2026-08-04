<?php

namespace App\Modules\Hr\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/** A dated BIR graduated withholding-tax table (FIN-027). */
class TaxVersion extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $table = 'hr_tax_versions';

    protected $fillable = ['effective_from', 'note', 'created_by'];

    protected $casts = ['effective_from' => 'date'];

    /** @return HasMany<TaxBracket, $this> */
    public function brackets(): HasMany
    {
        return $this->hasMany(TaxBracket::class, 'tax_version_id')->orderBy('min_annual');
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
