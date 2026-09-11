<?php

namespace App\Modules\Crm\Resources;

use App\Modules\Crm\Models\Note;
use App\Modules\Crm\Services\ProfilePhotoService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Note */
class NoteResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $authorName = $this->author?->name;

        return [
            'id' => (string) $this->id,
            'leadId' => (string) $this->lead_id,
            'authorId' => $this->user_id !== null ? (string) $this->user_id : null,
            'author' => $authorName ?? 'Unknown user',
            'initials' => $this->initialsFor($authorName),
            // The author's photo, from the one shared avatar source. Null → initials.
            'avatarUrl' => ProfilePhotoService::url($this->author?->avatar),
            'body' => $this->body,
            // The Tiptap document, or null for a legacy/imported note. That nullability is the
            // format discriminator the client renders on: null → the plain-text renderer that has
            // always been used; present → the read-only Tiptap renderer. No version flag needed.
            'bodyDoc' => $this->body_doc,
            // Whether the requesting user wrote this note (drives the self/other bubble styling).
            'self' => $this->user_id !== null && $request->user()?->id === $this->user_id,
            'createdAt' => $this->created_at?->toIso8601String(),
        ];
    }

    /** First letters of the first two name parts, uppercased (e.g. "Maria Santos" → "MS"). */
    private function initialsFor(?string $name): string
    {
        if ($name === null || trim($name) === '') {
            return '?';
        }

        $parts = array_values(array_filter(preg_split('/\s+/', trim($name)) ?: []));
        $letters = array_map(fn (string $p): string => mb_substr($p, 0, 1), array_slice($parts, 0, 2));

        return mb_strtoupper(implode('', $letters)) ?: '?';
    }
}
