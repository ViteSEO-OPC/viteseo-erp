<?php

namespace App\Modules\Crm\Resources;

use App\Modules\Crm\Models\EmailDesign;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A reusable email design as Settings → Email Designs edits it: its layout, whether it is the organization's
 * default, and how many templates use it.
 *
 * @mixin EmailDesign
 */
class EmailDesignResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id,
            'name' => $this->name,
            'isDefault' => (bool) $this->is_default,
            'layout' => $this->layout,
            'templatesCount' => (int) ($this->templates_count ?? $this->templates()->count()),
            'createdAt' => $this->created_at?->toIso8601String(),
            'updatedAt' => $this->updated_at?->toIso8601String(),
        ];
    }
}
