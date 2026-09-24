<?php

namespace App\Modules\Crm\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One paid provider's consumption for one period.
 *
 * PERSISTENCE ONLY, AND EMPHATICALLY SO. Reserving spend is a conditional UPDATE issued by
 * {@see \App\Modules\Crm\Services\Discovery\GooglePlacesSpendGate}; it deliberately does not go
 * through this model, because `$meter->micros_used += $price; $meter->save();` is precisely the
 * read-then-write race the single-statement design exists to eliminate. This model is for reading a
 * meter (diagnostics, the settings screen) and for creating an empty row — never for spending.
 *
 * NOT SOFT-DELETED, unlike HR records: a spend meter is an accounting artefact of a period that has
 * already happened. There is nothing to restore and nothing that may be hidden.
 *
 * `micros_used` is USD micros — a consumption counter, not platform money. The migration's docblock
 * explains why `decimal(12,2)` cannot hold it.
 *
 * @property string $provider
 * @property string $period `Y-m`
 * @property int $requests_used
 * @property int $micros_used Modelled gross spend, USD x 1,000,000
 * @property int|null $micros_allowed The live budget this row was last held to. Diagnostic only.
 * @property Carbon|null $reconciled_at
 * @property int|null $reconciled_micros
 */
#[Fillable([
    'provider',
    'period',
    'requests_used',
    'micros_used',
    'micros_allowed',
    'reconciled_at',
    'reconciled_micros',
])]
class ProviderSpendMeter extends Model
{
    protected $table = 'crm_provider_spend_meter';

    /**
     * Kept in lockstep with the migration's column defaults. Eloquent does not read database
     * defaults back into a new instance, so without these a freshly instantiated row would report
     * its counters as null and every caller would have to special-case "not saved yet".
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'requests_used' => 0,
        'micros_used' => 0,
    ];

    protected function casts(): array
    {
        return [
            'requests_used' => 'integer',
            'micros_used' => 'integer',
            'micros_allowed' => 'integer',
            'reconciled_micros' => 'integer',
            'reconciled_at' => 'datetime',
        ];
    }
}
