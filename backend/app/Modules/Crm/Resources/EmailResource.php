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
            // Additive. The Tiptap document of a formatted reply, rendered read-only by the Lead Detail
            // thread; null for every plain-text, inbound, draft and automation email, which keep
            // rendering `body` exactly as before. JSON, never HTML.
            'bodyDoc' => $this->body_doc,
            'status' => $this->status,
            'direction' => $this->direction,
            // RC3 Outreach Automation Pack — business purpose of the send (null for user-composed / inbound).
            'purpose' => $this->purpose,
            // Additive. The template the email was composed from, so a saved draft reopens on that
            // template's Purpose + Tone and its design. An id only; null when it was not.
            'emailTemplateId' => $this->email_template_id !== null ? (string) $this->email_template_id : null,
            // Additive. The Email Design a Lead Details reply was sent in, when the BD chose one — the signal the
            // thread uses to show that reply's sent HTML rather than its document. Null for every other email.
            'emailDesignId' => $this->email_design_id !== null ? (string) $this->email_design_id : null,
            // Additive. A per-lead structured email's content document (cards, rows, actions…), JSON never HTML;
            // null for every other email. Present so the structured editor can reopen the draft.
            'content' => $this->content,
            'provider' => $this->provider,
            'sentAt' => $this->sent_at?->toIso8601String(),
            'updatedAt' => $this->updated_at?->toIso8601String(),
            // RC3 Send-Later: dispatch time for a scheduled email (null for immediate/other states).
            'scheduledAt' => $this->scheduled_at?->toIso8601String(),
            'cancelledAt' => $this->cancelled_at?->toIso8601String(),
            // RC3 Planner link: when set, the schedule is owned by this Planner Email-Task event
            // (its starts_at is authoritative; the email carries no independent scheduled_at).
            'plannerEventId' => $this->planner_event_id !== null ? (string) $this->planner_event_id : null,
            // Inbound sender identity + receipt time — populated for `direction=inbound` rows and
            // consumed by the Lead Details Email Thread panel (additive; outbound rows leave these null).
            'fromEmail' => $this->from_email,
            'fromName' => $this->from_name,
            'receivedAt' => $this->received_at?->toIso8601String(),
            // The CRM user who sent an outbound message (only when the relation is eager-loaded).
            'senderName' => $this->whenLoaded('sender', fn () => $this->sender?->name),
            // Unified conversation ordering key: inbound receipt / outbound send / row creation.
            'timestamp' => ($this->received_at ?? $this->sent_at ?? $this->created_at)?->toIso8601String(),
        ];
    }
}
