<?php

declare(strict_types=1);

namespace App\Modules\Crm\Notifications\Presenters;

/**
 * `lead_assigned` — an Admin or Super Admin handed a lead to this BD. Payload
 * written by LeadAssignedNotification.
 *
 * The lead leads the title and the actor sits in the message: what the BD needs
 * first is WHICH lead is now theirs, and only then who gave it to them. That split
 * also matches the LeadAssigned toast's wording, so the transient and the durable
 * form of the same fact read alike.
 */
final class LeadAssignedPresenter implements NotificationPresenter
{
    use LinksToLead;

    /**
     * Falls back to a generic noun rather than an empty string: a lead whose
     * business_name was blank at assignment time must not render a bare
     * "New lead assigned:" with nothing after it.
     *
     * @param  array<string, mixed>  $data
     */
    public function title(array $data): string
    {
        return 'New lead assigned: '.((string) ($data['business_name'] ?? '') ?: 'a lead');
    }

    /**
     * Attribution, omitted entirely when the payload has no actor — older rows, or
     * any future system-driven assignment, should show no line rather than
     * "Assigned by".
     *
     * @param  array<string, mixed>  $data
     */
    public function message(array $data): ?string
    {
        $actor = (string) ($data['assigned_by_name'] ?? '');

        return $actor === '' ? null : 'Assigned by '.$actor;
    }

    /** @param array<string, mixed> $data */
    public function link(array $data): ?string
    {
        // The lead itself — the whole point of the notification is to go work it.
        return $this->leadLink($data);
    }
}
