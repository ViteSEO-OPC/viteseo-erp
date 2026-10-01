<?php

namespace App\Modules\Crm\Services\Copilot\Retrieval;

use App\Models\User;
use App\Modules\Crm\Models\Activity;
use App\Modules\Crm\Models\Lead;
use App\Modules\Crm\Services\Copilot\Knowledge\LeadActivityLabels;
use Illuminate\Support\Carbon;

/**
 * The sales-relevant activities logged on the one lead the question is about (Phase 2 Slice 4, PO
 * decisions 2026-10-01). Read from `crm_activities` — the lead's timeline — through the closed
 * {@see LeadActivityLabels} allowlist only:
 *
 *   - a count per kind (connected calls, unconnected call attempts, emails sent, email replies,
 *     notes, completed follow-ups / meetings / consultations / tasks), all logged so far;
 *   - lead_outcome — `won`, `lost` or `none`, from the newest won/lost entry;
 *   - the three newest allowlisted entries, newest first: a fixed label, the Asia/Manila calendar
 *     date, and who logged it as `you`, `a teammate` or `system` (logged automatically). The first is
 *     `last_logged_activity` — the newest SALES-RELEVANT entry, not the newest timeline row, which
 *     may be an assignment, edit, deletion or restore.
 *
 * Visibility is the existing boundary twice over: the lead is an {@see AuthorizedLead} (org scope,
 * active, visibleTo, LeadPolicy::view), and the rows are read through Activity::visibleTo — the same
 * scope every activity surface uses — narrowed to this lead as the subject. The query selects the
 * event, its time, its causer (compared with the viewer and dropped) and the two single property
 * values a label needs; never a description, the properties object, a subject, title, reason, amount
 * or any id. The deterministic last-activity reply reads its own endpoint and is not affected.
 */
class LeadActivityRetriever implements LeadScopedCapability
{
    public const KEY = 'lead_activity';

    /** How many of the newest entries are listed (PO decision, 2026-10-01). */
    public const RECENT = 3;

    /** The count fact for each kind; won and lost travel as `lead_outcome` instead. */
    private const COUNT_FACTS = [
        LeadActivityLabels::CONNECTED_CALL => 'lead_connected_calls',
        LeadActivityLabels::UNCONNECTED_CALL => 'lead_unconnected_calls',
        LeadActivityLabels::EMAIL_SENT => 'lead_emails_sent',
        LeadActivityLabels::EMAIL_REPLY => 'lead_email_replies',
        LeadActivityLabels::NOTE => 'lead_notes_added',
        LeadActivityLabels::FOLLOW_UP => 'lead_follow_ups_completed',
        LeadActivityLabels::MEETING => 'lead_meetings_completed',
        LeadActivityLabels::CONSULTATION => 'lead_consultations_completed',
        LeadActivityLabels::TASK => 'lead_tasks_completed',
    ];

    /** Names of the newest entries, newest first. No digits: a number in a fact name would ground. */
    public const RECENT_FACTS = ['last_logged_activity', 'second_last_logged_activity', 'third_last_logged_activity'];

    public const BY_YOU = 'you';

    public const BY_TEAMMATE = 'a teammate';

    public const BY_SYSTEM = 'system';

    public function key(): string
    {
        return self::KEY;
    }

    public function permits(User $user): bool
    {
        return $user->can('crm.leads.view');
    }

    public function retrieve(User $user, AuthorizedLead $lead, string $question): RetrievedFacts
    {
        $rows = Activity::query()
            ->visibleTo($user)
            ->where('subject_type', (new Lead)->getMorphClass())
            ->where('subject_id', $lead->id())
            ->whereIn('event', LeadActivityLabels::EVENTS)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get(['event', 'created_at', 'causer_id', 'properties->outcome as outcome', 'properties->event_type as event_type']);

        $counts = array_fill_keys(array_keys(self::COUNT_FACTS), 0);
        $outcome = 'none';
        $recent = [];
        foreach ($rows as $row) {
            $classified = LeadActivityLabels::classify((string) $row->event, $row->getAttribute('outcome'), $row->getAttribute('event_type'));
            if ($classified === null) {
                continue;
            }
            [$kind, $label] = $classified;
            if (isset($counts[$kind])) {
                $counts[$kind]++;
            } elseif ($outcome === 'none') {
                $outcome = $kind; // rows are newest first: the first won/lost entry is the current one
            }
            if (count($recent) < self::RECENT) {
                $recent[] = [
                    $label,
                    $row->created_at === null ? null : Carbon::instance($row->created_at)->setTimezone('Asia/Manila')->format('Y-m-d'),
                    $this->by($user, $row->causer_id),
                ];
            }
        }

        $facts = [];
        foreach (self::COUNT_FACTS as $kind => $name) {
            $facts[] = RetrievedFacts::fact($name, $counts[$kind], RetrievedFacts::COUNT);
        }
        $facts[] = RetrievedFacts::fact('lead_outcome', $outcome, RetrievedFacts::LABEL);
        foreach (self::RECENT_FACTS as $i => $name) {
            [$label, $date, $by] = $recent[$i] ?? [null, null, null];
            $facts[] = RetrievedFacts::fact($name, $label, RetrievedFacts::LABEL);
            $facts[] = RetrievedFacts::fact($name.'_date', $date, RetrievedFacts::DATE);
            $facts[] = RetrievedFacts::fact($name.'_by', $by, RetrievedFacts::LABEL);
        }

        return new RetrievedFacts(
            self::KEY,
            'Sales-relevant activities logged on this lead\'s timeline, from a fixed list only: calls (connected, or an attempt with no answer, busy or voicemail), emails sent, email replies received, notes added, completed follow-ups, meetings, consultations and tasks, and the lead being marked won or lost. Each count covers everything logged on this lead so far. lead_outcome is won, lost or none. last_logged_activity is the newest of these activities, and second_last_logged_activity and third_last_logged_activity came before it (null when there is none): a fixed label, its Philippine calendar date, and who logged it ("you", "a teammate", or "system" when the CRM logged it automatically, such as an inbound reply). Only these labels, dates and counts are known: nothing that any note, email, call or meeting said or contained. Assignments, edits, deletions and restores are not listed, so the lead\'s timeline can show newer entries.',
            $facts,
            $rows->count(),
            FactScope::LEAD,
        );
    }

    /** Who logged an entry, relative to the viewer. The causer's id never leaves this method. */
    private function by(User $user, mixed $causerId): string
    {
        return match (true) {
            $causerId === null => self::BY_SYSTEM,
            (int) $causerId === (int) $user->getKey() => self::BY_YOU,
            default => self::BY_TEAMMATE,
        };
    }
}
