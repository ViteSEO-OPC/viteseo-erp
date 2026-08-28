<?php

namespace App\Modules\Crm\Resources;

use App\Modules\Crm\Models\Activity;
use App\Modules\Crm\Services\ProfilePhotoService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Activity */
class ActivityResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id,
            'event' => $this->event,
            'description' => $this->description,
            'actor' => $this->causer?->name,
            // The actor's photo, from the one shared avatar source. Null for a system-generated
            // activity (no causer) or a user who has not uploaded one — the row then falls back to
            // the event-type icon tile, which is the existing behaviour for every row today.
            // `causer` is already eager-loaded by ActivityController, so this adds no query.
            'actorAvatarUrl' => ProfilePhotoService::url($this->causer?->avatar),
            'changes' => $this->properties,
            'timestamp' => $this->created_at?->toIso8601String(),
            // Polymorphic subject (Lead this sprint). Only emitted when the relation is
            // eager-loaded (the generic /activities feed) — the per-lead endpoint omits it
            // to avoid an N+1, since the caller already knows the lead.
            'subject' => $this->whenLoaded('subject', fn () => $this->subject === null ? null : [
                'type' => class_basename($this->subject_type),
                'id' => (string) $this->subject_id,
                'label' => $this->subject->business_name ?? null,
            ]),
        ];
    }
}
