<?php

namespace App\Modules\Hr\Http\Resources;

use App\Modules\Hr\Services\PrivateFileService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One sent (or failed) candidate email, for the history list on the candidate drawer.
 *
 * @mixin \App\Modules\Hr\Models\CandidateEmail
 */
class CandidateEmailResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'candidate_id' => $this->candidate_id,
            'type' => $this->type,
            'to_email' => $this->to_email,
            'cc' => $this->cc ?? [],
            'bcc' => $this->bcc ?? [],
            'reply_to' => $this->reply_to,
            'subject' => $this->subject,
            'body_html' => $this->body_html,
            'attachments' => collect($this->attachments ?? [])->map(fn (array $file) => [
                'name' => $file['name'],
                'size' => $file['size'] ?? null,
                'mime' => $file['mime'] ?? null,
                // Signed and short-lived, like every other private file in the module.
                'url' => PrivateFileService::url($file['path'] ?? null),
            ])->values()->all(),
            'status' => $this->status,
            'error' => $this->error,
            'sent_at' => $this->sent_at?->toIso8601String(),
            'sent_by' => $this->sentBy?->name,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
