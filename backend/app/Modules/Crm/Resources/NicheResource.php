<?php

namespace App\Modules\Crm\Resources;

use App\Modules\Crm\Models\Niche;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Niche */
class NicheResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id,
            'name' => $this->name,
            // false = built-in: the modal neither lists it under "Custom niches" nor offers
            // rename/delete for it, mirroring the server-side guards in NicheController.
            'isCustom' => $this->is_custom,
            // Live leads currently carrying this niche. Set by NicheController::index in one grouped
            // query (never per-row); falls back to the model's own count for single-resource
            // responses (store/update), where there is no N+1 to avoid.
            'leadCount' => (int) ($this->lead_count ?? $this->leadCount()),
        ];
    }
}
