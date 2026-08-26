<?php

declare(strict_types=1);

namespace App\Modules\Crm\Notifications\Presenters;

/**
 * `leads_reassigned` — an Admin handed a departing BD's whole portfolio to this BD.
 * Payload written by LeadsReassignedNotification.
 *
 * ONE row for a whole handover, unlike `lead_assigned`, which is one row per lead. Thirteen
 * individual bells for a single Admin click buries the thing it is announcing; the per-lead
 * `lead.assigned` Activity records remain the granular audit trail.
 *
 * The count leads the title and the story sits in the message, mirroring
 * {@see LeadAssignedPresenter}: what the recipient needs first is HOW MUCH work just landed, and
 * only then who moved it and whose it was.
 */
final class LeadsReassignedPresenter implements NotificationPresenter
{
    /**
     * Counts the leads when the payload says how many, and stays generic when it does not — an
     * older row missing `lead_count` must not render "0 leads reassigned to you".
     *
     * @param  array<string, mixed>  $data
     */
    public function title(array $data): string
    {
        $leads = (int) ($data['lead_count'] ?? 0);

        return $leads > 0
            ? sprintf('%d lead%s reassigned to you', $leads, $leads === 1 ? '' : 's')
            : 'Leads reassigned to you';
    }

    /**
     * The full sentence: who moved the work, how much of it, whose it was, and what open Planner
     * work came with it.
     *
     * Null when the payload names neither an actor nor a source — there is no sentence to write
     * without them, and the UI renders nothing rather than filler. A sparse historical row
     * therefore shows its title alone.
     *
     * @param  array<string, mixed>  $data
     */
    public function message(array $data): ?string
    {
        $actor = (string) ($data['reassigned_by_name'] ?? '');
        $from = (string) ($data['from_user_name'] ?? '');

        if ($actor === '' || $from === '') {
            return null;
        }

        $leads = (int) ($data['lead_count'] ?? 0);
        $planner = (int) ($data['planner_count'] ?? 0);

        return sprintf(
            '%s reassigned %d lead%s from %s to you%s.',
            $actor,
            $leads,
            $leads === 1 ? '' : 's',
            $from,
            $planner > 0
                ? sprintf(', along with %d open Planner item%s', $planner, $planner === 1 ? '' : 's')
                : '',
        );
    }

    /**
     * The Leads list, not a single lead: a handover has no one subject to open, and the recipient's
     * next action is to look over everything that just became theirs.
     *
     * Conditional on the payload actually describing a handover, so an empty row invents no link —
     * the same rule every presenter here follows.
     *
     * @param  array<string, mixed>  $data
     */
    public function link(array $data): ?string
    {
        if ((int) ($data['lead_count'] ?? 0) < 1 && (int) ($data['planner_count'] ?? 0) < 1) {
            return null;
        }

        // Planner-only handovers still land on Leads: the Planner board is reachable from there, and
        // splitting the destination by payload shape would make the same notification behave
        // differently for reasons the reader cannot see.
        return '/zerem/leads';
    }
}
