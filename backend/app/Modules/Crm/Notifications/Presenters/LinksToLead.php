<?php

declare(strict_types=1);

namespace App\Modules\Crm\Notifications\Presenters;

/**
 * Shared deep-link to a lead. Most CRM notifications are about something that
 * happened *to a lead*, so a lead-scoped payload should always resolve to the same
 * place — keeping the route in one spot means a future change to the Lead Details
 * path cannot leave some notification types pointing at a dead URL.
 */
trait LinksToLead
{
    /**
     * `/zerem/leads/{id}` when the payload identifies a lead, otherwise null.
     *
     * @param  array<string, mixed>  $data
     */
    protected function leadLink(array $data): ?string
    {
        // Loose emptiness check, matching the previous behaviour: a payload written
        // with a null or 0 lead_id must not produce `/zerem/leads/0`.
        if (empty($data['lead_id'])) {
            return null;
        }

        return '/zerem/leads/'.$data['lead_id'];
    }
}
