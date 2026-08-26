<?php

namespace App\Modules\Crm\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A frozen performance reading, for the Admin-facing history of a BD who has left the pipeline.
 *
 * `cohort` is exposed alongside `metrics` and not summarised away: the BDR index inside `metrics` is
 * peer-relative, so a client that renders it without the peer set it was normalized against is
 * showing a number it cannot justify.
 *
 * @property \App\Modules\Crm\Models\BdPerformanceSnapshot $resource
 */
class BdPerformanceSnapshotResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id,
            'userId' => (string) $this->user_id,
            'capturedAt' => $this->captured_at,
            'reason' => $this->reason,
            'metrics' => $this->metrics,
            'cohort' => $this->cohort,
        ];
    }
}
