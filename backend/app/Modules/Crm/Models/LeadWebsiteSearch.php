<?php

namespace App\Modules\Crm\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Whether we have looked for this lead's website, how hard, and what a human concluded.
 *
 * Three states worth keeping apart, because two of them look identical from the candidates table
 * alone: NEVER SEARCHED (no row, or no tiers run), SEARCHED AND EXHAUSTED (every available tier ran
 * and nothing survived), and CONFIRMED ABSENT (a person read the evidence and accepted it).
 *
 * Only the third justifies `no-website`. Exhaustion on its own is a search miss, which is weaker
 * evidence than a human check — the business may hold a site no tier surfaced.
 *
 * @property array<int, string>|null $tiers_run
 * @property Carbon|null $last_searched_at Cast to datetime; declared so static analysis applies the cast.
 * @property Carbon|null $exhausted_at Cast to datetime; declared so static analysis applies the cast.
 * @property Carbon|null $confirmed_absent_at Cast to datetime; declared so static analysis applies the cast.
 */
#[Fillable([
    'lead_id', 'tiers_run', 'candidates_found', 'candidates_rejected',
    'last_searched_at', 'exhausted_at', 'confirmed_absent_at', 'confirmed_by', 'confirmation_note',
])]
class LeadWebsiteSearch extends Model
{
    protected $table = 'crm_lead_website_searches';

    protected $attributes = [
        'candidates_found' => 0,
        'candidates_rejected' => 0,
    ];

    protected function casts(): array
    {
        return [
            'tiers_run' => 'array',
            'last_searched_at' => 'datetime',
            'exhausted_at' => 'datetime',
            'confirmed_absent_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Lead, $this> */
    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class, 'lead_id');
    }

    /** @return BelongsTo<User, $this> */
    public function confirmedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmed_by');
    }

    /** Every available tier ran and nothing survived. Evidence, not a verdict. */
    public function isExhausted(): bool
    {
        return $this->exhausted_at !== null;
    }

    public function isConfirmedAbsent(): bool
    {
        return $this->confirmed_absent_at !== null;
    }
}
