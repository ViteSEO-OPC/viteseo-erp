<?php

namespace App\Modules\Crm\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Str;

/**
 * Presents a standard Laravel Database Notification (the RC2 `notifications` table) for the in-app
 * Notification Center. Read-only: it derives a human title/message and a deep-link to the related CRM
 * record from the existing `data` payload written by the Notification classes (InboundReplyNotification,
 * PlannerReminderNotification, …). No new storage, no business logic — just presentation.
 *
 * @mixin DatabaseNotification
 */
class NotificationResource extends JsonResource
{
    // ⚠ CONVENTION (approved 2026-07-16): every notification type MUST explicitly define its
    // title (deriveTitle), message (deriveMessage) and deep-link (deriveLink) here, AND its icon in
    // the frontend `ICON_BY_TYPE` (notification-item.tsx). The `default` arms below are a safety net
    // to avoid crashes — NOT an acceptable end state. A new Notification class is not "done" until it
    // has meaningful title/icon/deep-link, never the generic fallback.

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var array<string, mixed> $data */
        $data = (array) $this->data;
        $type = (string) ($data['type'] ?? class_basename($this->type));

        return [
            'id' => (string) $this->id,
            'type' => $type,
            'title' => $this->deriveTitle($type, $data),
            'message' => $this->deriveMessage($type, $data),
            // Deep-link to the related CRM record; null when the payload has no addressable target.
            'link' => $this->deriveLink($data),
            'data' => $data,
            'read' => $this->read_at !== null,
            'read_at' => $this->read_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function deriveTitle(string $type, array $data): string
    {
        return match ($type) {
            'inbound_reply' => 'New reply from '.(string) ($data['from_name'] ?? $data['from'] ?? 'a prospect'),
            'planner_reminder' => 'Reminder: '.(string) ($data['title'] ?? 'Upcoming event'),
            default => Str::headline($type),
        };
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function deriveMessage(string $type, array $data): ?string
    {
        return match ($type) {
            'inbound_reply' => (string) ($data['subject'] ?? $data['preview'] ?? '') ?: null,
            'planner_reminder' => trim(implode(' · ', array_filter([
                isset($data['event_type']) ? Str::headline((string) $data['event_type']) : null,
                isset($data['lead_name']) ? (string) $data['lead_name'] : null,
            ]))) ?: null,
            default => null,
        };
    }

    /**
     * Centralized deep-link derivation. A lead-scoped payload always points at the Lead detail page;
     * a Planner reminder without a lead falls back to the Planner. Extend here as new notification
     * types are added — the frontend stays generic.
     *
     * @param  array<string, mixed>  $data
     */
    private function deriveLink(array $data): ?string
    {
        if (! empty($data['lead_id'])) {
            return '/leads/'.$data['lead_id'];
        }

        if (! empty($data['planner_event_id'])) {
            return '/planner';
        }

        return null;
    }
}
