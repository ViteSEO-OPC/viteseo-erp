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
 * `content` is a designed template's stored content, or null for a plain-text template; for a designed
 * template `body` is the plain-text form the server derived from its design and content. `emailDesignId` and
 * `emailDesignName` name the design it is shown in.
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
            'content' => $this->content,
            'emailDesignId' => $this->email_design_id === null ? null : (string) $this->email_design_id,
            'emailDesignName' => $this->email_design_id === null ? null : $this->design?->name,
            'isDesigned' => $this->isDesigned(),
            'isActive' => (bool) $this->is_active,
            'createdAt' => $this->created_at?->toIso8601String(),
            'updatedAt' => $this->updated_at?->toIso8601String(),
        ];
    }
}
