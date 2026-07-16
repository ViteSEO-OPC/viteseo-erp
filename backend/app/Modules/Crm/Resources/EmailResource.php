<?php

namespace App\Modules\Crm\Resources;

use App\Modules\Crm\Models\Email;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Email */
class EmailResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id,
            'leadId' => $this->lead_id !== null ? (string) $this->lead_id : null,
            'to' => $this->to_email,
            'subject' => $this->subject,
            // Included so a draft can be loaded back into the composer for editing.
            'body' => $this->body,
            'status' => $this->status,
            'direction' => $this->direction,
            'provider' => $this->provider,
            'sentAt' => $this->sent_at?->toIso8601String(),
            'updatedAt' => $this->updated_at?->toIso8601String(),
        ];
    }
}
