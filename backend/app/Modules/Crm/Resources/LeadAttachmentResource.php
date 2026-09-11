<?php

namespace App\Modules\Crm\Resources;

use App\Modules\Crm\Models\LeadAttachment;
use App\Modules\Crm\Services\Attachments\DocumentPreviewService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin LeadAttachment */
class LeadAttachmentResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id,
            // Echoed so the client can assert an attachment belongs to the lead it is rendering.
            'leadId' => (string) $this->lead_id,
            'name' => $this->original_name,
            'mimeType' => $this->mime_type,
            'size' => $this->size,
            // Preformatted on the server so every surface renders the same units from the same rule.
            'sizeLabel' => $this->humanSize($this->size),
            'uploadedById' => $this->user_id !== null ? (string) $this->user_id : null,
            'uploadedBy' => $this->whenLoaded('uploader', fn (): string => $this->uploader?->name ?? 'Unknown user', 'Unknown user'),
            'createdAt' => $this->created_at?->toIso8601String(),
            // The capability flag behind the DOC/DOCX/PPT/PPTX View button: true only when this
            // server can convert this attachment's Office format. A server with no conversion
            // service configured answers false and the client keeps those files Download-only.
            // An affordance, not an authorization — the preview endpoint re-checks the bytes and
            // the lead policy.
            'documentPreview' => app(DocumentPreviewService::class)->isOfferedFor($this->resource),
        ];
    }

    /** Bytes as a compact human label ("317 KB", "1.2 MB"). Matches the upload modal's file line. */
    private function humanSize(int $bytes): string
    {
        if ($bytes < 1024) {
            return $bytes . ' B';
        }

        $units = ['KB', 'MB', 'GB', 'TB'];
        $value = $bytes / 1024;
        $unit = 0;

        while ($value >= 1024 && $unit < count($units) - 1) {
            $value /= 1024;
            $unit++;
        }

        // One decimal below 10 ("1.2 MB"), none above ("317 KB") — the mockup's convention.
        return ($value < 10 ? round($value, 1) : round($value)) . ' ' . $units[$unit];
    }
}
