<?php

namespace App\Modules\Crm\Resources;

use App\Modules\Crm\Models\EmailTemplate;
use App\Modules\Crm\Support\EmailTemplateCatalog;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * An email template as Settings → Email Templates edits it: the RAW authored subject and body with
 * their `{{tokens}}`. Rendering for a lead stays the composer endpoint's job.
 *
 * @mixin EmailTemplate
 */
class EmailTemplateResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id,
            'purpose' => $this->purpose,
            'tone' => $this->tone,
            'kind' => EmailTemplateCatalog::kindOf($this->purpose),
            'subject' => $this->subject,
            'body' => $this->body,
            'isActive' => (bool) $this->is_active,
            'createdAt' => $this->created_at?->toIso8601String(),
            'updatedAt' => $this->updated_at?->toIso8601String(),
        ];
    }
}
