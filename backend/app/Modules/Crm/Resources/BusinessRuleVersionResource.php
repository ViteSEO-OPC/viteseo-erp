<?php

namespace App\Modules\Crm\Resources;

use App\Modules\Crm\Models\BusinessRuleVersion;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * API shape for a Business Rule version (active, draft, or archived). `rules` is included only when
 * eager-loaded (the detail/read views); the version-history list omits it. Enum fields serialize to their
 * backing string; the frontend maps display labels. IDs are stringified (mirrors the CRM resource idiom).
 *
 * @mixin BusinessRuleVersion
 */
class BusinessRuleVersionResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id,
            'module' => $this->module->value,
            'versionNumber' => $this->version_number,
            'state' => $this->state->value,
            'computationSpecVersion' => $this->computation_spec_version,
            'reasonForChange' => $this->reason_for_change,
            'createdBy' => $this->created_by !== null ? (string) $this->created_by : null,
            'publishedBy' => $this->published_by !== null ? (string) $this->published_by : null,
            'publishedAt' => $this->published_at?->toIso8601String(),
            'createdAt' => $this->created_at?->toIso8601String(),
            'rules' => BusinessRuleResource::collection($this->whenLoaded('rules')),
        ];
    }
}
