<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Resources;

use App\Modules\Accounting\Models\JournalEntry;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin JournalEntry
 */
class JournalEntryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'entry_date' => $this->entry_date?->toDateString(),
            'reference' => $this->reference,
            'memo' => $this->memo,
            'source_type' => $this->source_type->value,
            'status' => $this->status->value,
            'posted_at' => $this->posted_at?->toIso8601String(),
            'lines' => JournalLineResource::collection($this->whenLoaded('lines')),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
