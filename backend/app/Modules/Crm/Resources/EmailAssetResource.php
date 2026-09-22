<?php

namespace App\Modules\Crm\Resources;

use App\Modules\Crm\Models\EmailAsset;
use App\Modules\Crm\Services\Outreach\EmailAssetService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * An email asset as Settings shows it. `url` is the public PNG email clients receive; the SVG an SVG upload
 * keeps is not exposed.
 *
 * @mixin EmailAsset
 */
class EmailAssetResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $meta = is_array($this->meta) ? $this->meta : [];

        return [
            'id' => $this->uuid,
            'sourceFormat' => $this->source_format,
            'url' => app(EmailAssetService::class)->publicUrl($this->resource),
            'width' => $this->width,
            'height' => $this->height,
            'bytes' => $this->bytes,
            'originalName' => $meta['original_name'] ?? null,
            'removed' => (object) ($meta['removed'] ?? []),
            'createdAt' => $this->created_at?->toIso8601String(),
        ];
    }
}
