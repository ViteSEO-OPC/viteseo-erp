<?php

declare(strict_types=1);

namespace App\Modules\Crm\Enums;

use App\Modules\Crm\Notifications\Presenters\InboundReplyPresenter;
use App\Modules\Crm\Notifications\Presenters\LeadAssignedPresenter;
use App\Modules\Crm\Notifications\Presenters\LeadsReassignedPresenter;
use App\Modules\Crm\Notifications\Presenters\NotificationPresenter;
use App\Modules\Crm\Notifications\Presenters\PlannerReminderPresenter;

/**
 * The CRM notification catalog — the `data->type` discriminator every Notification
 * class writes, and the key the Notification Center presents a row by.
 *
 * ⚠ NOT the `notifications.type` COLUMN. That column holds the Notification class
 * FQCN (Laravel's own use of it); the catalog key lives at `data->type`. Anything
 * presenting a notification must read the payload, never the column.
 *
 * A case exists here only once it has a producer AND a presenter. That is
 * deliberate: `presenter()` matches without a `default` arm, so adding a case and
 * forgetting its presenter is an immediate \UnhandledMatchError rather than a row
 * that quietly renders as a humanised type string. `NotificationCatalogTest` walks
 * every case to keep that honest. The catalog therefore grows one case at a time,
 * alongside the producer that emits it — see the roadmap below.
 *
 * Planned (each lands with its producer + presenter, not before):
 *   lead_status_changed · lead_converted · lead_imported · lead_note_added
 *   followup_assigned · followup_completed · email_received · meeting_scheduled
 *
 * Values are snake_case. An earlier version of this list wrote them dotted
 * (`lead.assigned`), which never matched the two shipped cases; `lead_assigned`
 * settled it in favour of the persisted convention, and the rest are spelled
 * that way here so the next producer does not have to re-decide.
 *
 * The cases below keep the exact string values written since RC2 — renaming any
 * would orphan every notification already stored, because the value is persisted
 * inside each row's `data` payload.
 */
enum NotificationType: string
{
    case INBOUND_REPLY = 'inbound_reply';
    case PLANNER_REMINDER = 'planner_reminder';
    case LEAD_ASSIGNED = 'lead_assigned';
    case LEADS_REASSIGNED = 'leads_reassigned';

    /**
     * The presenter that turns this type's payload into title / message / deep-link.
     *
     * No `default` arm on purpose — see the class docblock.
     */
    public function presenter(): NotificationPresenter
    {
        return match ($this) {
            self::INBOUND_REPLY => new InboundReplyPresenter,
            self::PLANNER_REMINDER => new PlannerReminderPresenter,
            self::LEAD_ASSIGNED => new LeadAssignedPresenter,
            self::LEADS_REASSIGNED => new LeadsReassignedPresenter,
        };
    }
}
