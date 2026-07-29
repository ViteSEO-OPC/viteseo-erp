<?php

namespace App\Modules\Crm\Resources;

use App\Modules\Crm\Enums\NotificationType;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Str;

/**
 * Presents a standard Laravel Database Notification (the RC2 `notifications` table)
 * for the in-app Notification Center. Read-only: title, message and deep-link are
 * derived from the `data` payload the Notification classes already write. No new
 * storage, no business logic — just presentation.
 *
 * ⚠ CONVENTION (approved 2026-07-16, unchanged): every notification type MUST define
 * a meaningful title, message and deep-link, plus its icon in the frontend
 * `ICON_BY_TYPE`. What changed is enforcement: the three `match` statements that used
 * to live here are now one presenter per type behind NotificationType::presenter(),
 * which matches exhaustively — a new case without a presenter throws instead of
 * quietly rendering a humanised type string. See NotificationCatalogTest.
 *
 * @mixin DatabaseNotification
 */
class NotificationResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var array<string, mixed> $data */
        $data = (array) $this->data;
        // The catalog key lives in the payload; the `type` COLUMN holds the
        // Notification class FQCN. class_basename covers rows written before the
        // producer set an explicit `data->type`.
        $type = (string) ($data['type'] ?? class_basename($this->type));

        $presenter = NotificationType::tryFrom($type)?->presenter();

        return [
            'id' => (string) $this->id,
            'type' => $type,
            // The fallbacks below are for UNREGISTERED types only — legacy rows, or a
            // type retired from the catalog while its rows live on. They are not a
            // path a current type can take: every enum case has a presenter, and the
            // catalog test proves it. Kept so old rows degrade to something readable
            // instead of vanishing or throwing mid-render.
            'title' => $presenter?->title($data) ?? Str::headline($type),
            'message' => $presenter?->message($data),
            'link' => $presenter?->link($data) ?? $this->legacyLink($data),
            'data' => $data,
            'read' => $this->read_at !== null,
            'read_at' => $this->read_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }

    /**
     * Deep-link for a type with no presenter. Mirrors the generic derivation that
     * applied to every type before the registry existed, so an unregistered row
     * keeps resolving exactly where it has since RC2.
     *
     * @param  array<string, mixed>  $data
     */
    private function legacyLink(array $data): ?string
    {
        if (! empty($data['lead_id'])) {
            return '/zerem/leads/'.$data['lead_id'];
        }

        if (! empty($data['planner_event_id'])) {
            return '/zerem/planner';
        }

        return null;
    }
}
