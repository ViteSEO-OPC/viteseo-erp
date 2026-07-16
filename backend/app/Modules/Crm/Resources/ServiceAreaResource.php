<?php

namespace App\Modules\Crm\Resources;

use App\Modules\Crm\Models\ServiceArea;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin ServiceArea */
class ServiceAreaResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id,
            'name' => $this->name,
            'level' => $this->level,
            'active' => $this->active,
        ];
    }
}
