<?php

namespace App\Modules\Crm\Services\Dashboard;

use App\Models\User;
use App\Modules\Crm\Models\Email;
use App\Modules\Crm\Models\Lead;
use App\Modules\Crm\Models\PlannerEvent;
use Illuminate\Database\Eloquent\Builder;

/**
 * The dashboard's KPI figures and the pending follow-up definition, MOVED here verbatim from
 * DashboardController so a second reader (the Copilot) uses the SAME definitions rather than a copy.
 *
 * Behaviour-preserving by construction and by test: DashboardMetricsParityTest holds byte-identical
 * snapshots of /dashboard/kpis and /dashboard/reports-analytics recorded before the move.
 *
 * Scope is the caller's: every method takes the already-authenticated user and applies the same
 * ownership scopes the controller always did (Lead::visibleTo, PlannerEvent::visibleTo).
 */
class DashboardMetricsService
{
    /**
     * The pending follow-up set — the ONE definition behind every follow-up queue figure the
     * dashboard serves (the dashboard's Follow-ups Due KPI and the reports page's overdue/today/
     * upcoming buckets).
     *
     * Frozen rule (Reports & Performance milestone): a Planner event of type `follow-up` that is
     * DATED and NOT completed, scoped by PlannerEvent::visibleTo — Admin/SA see the whole CRM, a BD
     * sees their own events plus events on the leads they own. An outbound email is deliberately NOT
     * a follow-up (PO ruling 2026-07-09); only real Planner events count here.
     *
     * Returned as a builder factory rather than a builder so each caller gets a fresh query — the
     * bucket counts below run four of them off this one definition.
     *
     * @return callable(): Builder<PlannerEvent>
     */
    public function pendingFollowUps(User $user): callable
    {
        return fn (): Builder => PlannerEvent::query()
            ->visibleTo($user)
            // The whole follow-up-work category ({@see PlannerEvent::scopeFollowUpWork}), not the bare
            // `follow-up` literal: an Email/Call/Meeting task that is due IS work due, and once Lead
            // Details routes the BD's channel choice into `event_type` those rows would otherwise
            // disappear from this card and its three buckets.
            ->followUpWork()
            ->where('status', '!=', 'completed')
            ->whereNotNull('starts_at');
    }

    /**
     * The Asia/Manila day boundary as half-open UTC windows, `[todayStart, todayEnd)`.
     *
     * Datetimes are stored UTC but the business day is Manila's (the platform rule), and the windows
     * are half-open so an event landing exactly on midnight is counted once — never in two buckets.
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    public function manilaDayWindow(): array
    {
        $tz = 'Asia/Manila';

        return [now($tz)->startOfDay()->utc(), now($tz)->startOfDay()->addDay()->utc()];
    }

    /**
     * Supported KPI counts (backend-computable only) + per-status breakdown — the `data` payload of
     * GET /dashboard/kpis. Scoped by ownership: Admin/SA see all leads; a BD sees only their own.
     *
     * @return array<string, mixed>
     */
    public function kpis(User $user): array
    {
        // Single status aggregation (same groupBy pattern as the website-status buckets);
        // powers both the `interested` KPI and the Lead Status Summary tab counts —
        // no duplicate aggregation, no new endpoint.
        $statusCounts = Lead::query()
            ->active()
            ->visibleTo($user)
            ->selectRaw('status, COUNT(*) as count')
            ->groupBy('status')
            ->pluck('count', 'status');

        return [
            'totalLeads' => Lead::query()->active()->visibleTo($user)->count(),
            'highPriority' => Lead::query()->active()->visibleTo($user)->where('priority', 'high')->count(),
            'interested' => (int) $statusCounts->get('interested', 0),
            // Follow-ups Due — the WHOLE scoped pending set (overdue + today + upcoming), which
            // is the same "actionable items" reading the Planner sidebar badge already uses. It
            // rides the shared pendingFollowUps() definition, so this card and the reports
            // page's three buckets can never drift apart: this is their sum by construction.
            'followUpsDue' => ($this->pendingFollowUps($user))()->count(),
            // Completed Outreach (PO decision) — outbound emails the system actually SENT.
            //
            // Counts ACTIONS, not leads: emailing one lead three times is three completed
            // outreach actions, which is what makes this an effort metric rather than a
            // coverage one (coverage is M-04, deliberately a different KPI).
            //
            // `crm_emails.status = 'sent'` is the source because it is written by the send
            // path itself. `crm_leads.email_outreach` is deliberately NOT used: nothing in the
            // codebase ever writes its `sent` value — only `replied`, by the inbound processor
            // — so it records what happened TO us, not what we did. Using it would let an
            // inbound reply credit outreach that was never performed.
            //
            // Every other status is excluded by being unequal to 'sent': draft, scheduled,
            // queued, failed, cancelled. Inbound mail cannot leak in — it is a different
            // direction AND carries `received`. Calls and Planner follow-ups are out of scope
            // by PO decision; no combined channel rule is invented here.
            'completedOutreach' => Email::query()
                ->where('direction', 'outbound')
                ->where('status', 'sent')
                ->whereIn('lead_id', Lead::query()->active()->visibleTo($user)->select('id'))
                ->count(),
            // Raw per-status counts (frozen enum keys). The UI applies its own
            // label mapping — keeping this response terminology-neutral.
            'statusCounts' => [
                'new' => (int) $statusCounts->get('new', 0),
                'interested' => (int) $statusCounts->get('interested', 0),
                'awaiting-reply' => (int) $statusCounts->get('awaiting-reply', 0),
                'call-back-req' => (int) $statusCounts->get('call-back-req', 0),
                'declined' => (int) $statusCounts->get('declined', 0),
            ],
        ];
    }

    /**
     * The pending follow-up set partitioned into overdue / today / upcoming by the Manila day — the
     * `pendingFollowUps.counts` of GET /dashboard/reports-analytics.
     *
     * @return array{overdue: int, today: int, upcoming: int}
     */
    public function followUpCounts(User $user): array
    {
        [$todayStart, $todayEnd] = $this->manilaDayWindow();

        $pending = $this->pendingFollowUps($user);

        return [
            // Half-open [start, end) windows — identical to the row classifier in reportsAnalytics, so an event
            // landing exactly on the Manila midnight boundary is counted once (in `upcoming`),
            // never in two buckets. The three counts always sum to the pending total.
            'overdue' => (clone $pending())->where('starts_at', '<', $todayStart)->count(),
            'today' => (clone $pending())
                ->where('starts_at', '>=', $todayStart)
                ->where('starts_at', '<', $todayEnd)
                ->count(),
            'upcoming' => (clone $pending())->where('starts_at', '>=', $todayEnd)->count(),
        ];
    }
}
