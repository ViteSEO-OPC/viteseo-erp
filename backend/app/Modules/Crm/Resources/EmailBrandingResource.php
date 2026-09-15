<?php

namespace App\Modules\Crm\Resources;

use App\Modules\Crm\Models\EmailBranding;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The organization's email branding as Settings shows it. An organization that has set nothing yet is the same
 * shape with no logo.
 *
 * @property EmailBranding|null $resource
 */
class EmailBrandingResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $logo = $this->resource?->logo;

        return [
            'logo' => $logo === null ? null : (new EmailAssetResource($logo))->toArray($request),
            'updatedAt' => $this->resource?->updated_at?->toIso8601String(),
        ];
    }
}
