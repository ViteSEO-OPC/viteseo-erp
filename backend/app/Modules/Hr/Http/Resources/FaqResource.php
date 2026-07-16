<?php

namespace App\Modules\Hr\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class FaqResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'question' => $this->question,
            'answer' => $this->answer,
            'category' => $this->category,
            'tags' => $this->tags ?? [],
            'status' => $this->status,
            'is_pinned' => $this->is_pinned,
            'view_count' => $this->view_count,
            'updated_by' => $this->whenLoaded('editor', fn () => $this->editor?->name),
            'updated_at' => $this->updated_at,
        ];
    }
}
