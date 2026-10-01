<?php

namespace App\Modules\Crm\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The AI fallback's outcome. `answered: false` tells the Copilot to keep its own help reply.
 *
 * @property string|null $resource
 */
class CopilotAnswerResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'answered' => $this->resource !== null,
            'text' => $this->resource,
        ];
    }
}
