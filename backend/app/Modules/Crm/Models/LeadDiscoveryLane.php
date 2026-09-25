<?php

namespace App\Modules\Crm\Models;

use App\Models\Concerns\BelongsToOrg;
use App\Modules\Crm\Services\Discovery\DiscoveryLaneRecorder;
use Illuminate\Database\Eloquent\Model;

/**
 * A further discovery lane that found a lead (see the crm_lead_discovery_lanes migration).
 *
 * READ-ONLY BY CONVENTION: this model exists so the Nearby board can eager-load a lead's lanes in one
 * query. Rows are written only by {@see DiscoveryLaneRecorder},
 * through the query builder, so no Eloquent event is ever raised for them.
 */
class LeadDiscoveryLane extends Model
{
    use BelongsToOrg;

    protected $table = 'crm_lead_discovery_lanes';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'first_seen_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'times_seen' => 'integer',
        ];
    }
}
