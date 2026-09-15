<?php

namespace App\Modules\Crm\Resources;

use App\Modules\Crm\Models\EmailTemplateOption;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A custom purpose or tone as Settings shows it.
 *
 * @mixin EmailTemplateOption
 */
class EmailTemplateOptionResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id,
            'type' => $this->type,
            'value' => $this->value,
            'createdAt' => $this->created_at?->toIso8601String(),
        ];
    }
}
