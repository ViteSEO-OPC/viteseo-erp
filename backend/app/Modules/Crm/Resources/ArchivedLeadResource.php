<?php

namespace App\Modules\Crm\Resources;

use App\Modules\Crm\Models\Lead;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Archive Management view of a soft-deleted lead. A dedicated resource (NOT an extension of
 * LeadResource) so the archive surface carries only what the archive list renders — identity,
 * status, owning BD, and the archive provenance (archivedAt + archivedBy) — without bloating the
 * primary LeadResource with archive-only fields.
 *
 * `archivedAt` is the soft-delete instant (`deleted_at`). `archivedBy` is derived from the lead's
 * latest `deleted` Activity causer via the `archiveActivity` one-of-many relation — there is no
 * `deleted_by` column; SoftDeletes' `deleted_at` remains the single archive state.
 *
 * @mixin Lead
 */
class ArchivedLeadResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id,
            'business' => $this->business_name,
            'contact' => $this->contact_name,
            'status' => $this->status,
            // Owning BD (may be null — many archived leads were never assigned).
            'assignedToId' => $this->assigned_to !== null ? (string) $this->assigned_to : null,
            'assignedTo' => $this->whenLoaded('assignedTo', fn () => $this->assignedTo ? [
                'id' => (string) $this->assignedTo->id,
                'name' => $this->assignedTo->name,
            ] : null),
            // Archive provenance — the single archive state is `deleted_at`.
            'archivedAt' => $this->deleted_at?->toIso8601String(),
            // Derived from the immutable `deleted` Activity causer (no `deleted_by` column).
            'archivedBy' => $this->whenLoaded('archiveActivity', fn () => $this->archiveActivity?->causer ? [
                'id' => (string) $this->archiveActivity->causer->id,
                'name' => $this->archiveActivity->causer->name,
            ] : null),
        ];
    }
}
