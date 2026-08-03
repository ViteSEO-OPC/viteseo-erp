<?php

declare(strict_types=1);

namespace App\Modules\Crm\Notifications\Presenters;

use Illuminate\Support\Str;

/**
 * `planner_reminder` — a Planner event is due. Payload written by
 * PlannerReminderNotification.
 *
 * Presentation is unchanged from the previous match arms in NotificationResource;
 * only its home moved.
 */
final class PlannerReminderPresenter implements NotificationPresenter
{
    use LinksToLead;

    /** @param array<string, mixed> $data */
    public function title(array $data): string
    {
        return 'Reminder: '.(string) ($data['title'] ?? 'Upcoming event');
    }

    /**
     * Event type and lead, joined only where present — a reminder with neither
     * shows no second line rather than stray separators.
     *
     * @param  array<string, mixed>  $data
     */
    public function message(array $data): ?string
    {
        return trim(implode(' · ', array_filter([
            isset($data['event_type']) ? Str::headline((string) $data['event_type']) : null,
            isset($data['lead_name']) ? (string) $data['lead_name'] : null,
        ]))) ?: null;
    }

    /**
     * A reminder tied to a lead opens the LEAD, not the Planner — the lead is
     * where the user acts on it. Only an unattached event falls back to the
     * Planner. This ordering is load-bearing and predates the presenters.
     *
     * @param  array<string, mixed>  $data
     */
    public function link(array $data): ?string
    {
        return $this->leadLink($data)
            ?? (empty($data['planner_event_id']) ? null : '/zerem/planner');
    }
}
