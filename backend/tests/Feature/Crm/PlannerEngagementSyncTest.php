<?php

namespace Tests\Feature\Crm;

use App\Models\User;
use App\Modules\Crm\Enums\CallOutcome;
use App\Modules\Crm\Listeners\SyncLeadEngagementOnCompletion;
use App\Modules\Crm\Models\Call;
use App\Modules\Crm\Models\Lead;
use App\Modules\Crm\Models\PlannerEvent;
use App\Modules\Crm\Services\Calling\CallSessionService;
use App\Modules\Crm\Services\Scoring\LeadScorer;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * PLANNER → LEAD ENGAGEMENT SYNC — {@see SyncLeadEngagementOnCompletion}.
 *
 * Nothing here fakes events: each test drives a REAL transition into `completed` and then reads the
 * lead back. Faking would assert the observer's contract (that FollowUpCompleted was dispatched),
 * which is already covered elsewhere and says nothing about what this listener does with it.
 *
 * The rules being pinned, all PO-approved 2026-08-14:
 *   - advance-only per column, so a completion can never destroy a more advanced state;
 *   - `call` and `email` ONLY — the other four planner types leave engagement alone;
 *   - the SALES status (`leads.status`) is never touched, by any path;
 *   - `last_activity` is never touched (deliberately unlike InboundReplyProcessor);
 *   - only a transition INTO `completed` fires — PLANNED != PERFORMED.
 */
class PlannerEngagementSyncTest extends TestCase
{
    use RefreshDatabase;

    private User $bd;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->bd = User::factory()->create(['status' => 'active']);
        $this->bd->assignRole('crm_admin');
    }

    /**
     * Every scoring input LeadFactory randomises is pinned here, so a score assertion can only move
     * because of what the test did. `cold_calling` especially: LeadObserver auto-creates a cold-call
     * follow-up on `scheduled`, which would add planner rows these tests never asked for.
     */
    private function lead(array $attributes = []): Lead
    {
        return Lead::factory()->create([
            'assigned_to' => $this->bd->id,
            'created_by' => $this->bd->id,
            'cold_calling' => 'not-called',
            'email_outreach' => 'none',
            'status' => 'new',
            'website_status' => 'no-website',
            'last_activity' => now()->subDays(3),
            ...$attributes,
        ]);
    }

    private function event(string $type, Lead $lead, string $status = 'todo'): PlannerEvent
    {
        return PlannerEvent::factory()->create([
            'lead_id' => $lead->id,
            'assigned_to' => $this->bd->id,
            'event_type' => $type,
            'status' => $status,
        ]);
    }

    /** The real transition the Board drag and the Edit dialog both perform. */
    private function complete(PlannerEvent $event): void
    {
        $event->update(['status' => 'completed']);
    }

    // ── The two synchronized channels ───────────────────────────────────────────────────────────

    public function test_completing_a_call_advances_cold_calling_to_called(): void
    {
        $lead = $this->lead();

        $this->complete($this->event('call', $lead));

        $this->assertSame('called', $lead->fresh()->cold_calling);
    }

    public function test_completing_an_email_advances_email_outreach_to_sent(): void
    {
        $lead = $this->lead();

        $this->complete($this->event('email', $lead));

        $this->assertSame('sent', $lead->fresh()->email_outreach);
    }

    /** PO-approved: performed outranks intent, so a booked slot yields to a completed dial. */
    public function test_scheduled_advances_to_called(): void
    {
        $lead = $this->lead(['cold_calling' => 'scheduled']);

        $this->complete($this->event('call', $lead));

        $this->assertSame('called', $lead->fresh()->cold_calling);
    }

    /** Each channel advances only its OWN column — a completed call says nothing about email. */
    public function test_each_channel_touches_only_its_own_column(): void
    {
        $lead = $this->lead();

        $this->complete($this->event('call', $lead));

        $lead->refresh();
        $this->assertSame('called', $lead->cold_calling);
        $this->assertSame('none', $lead->email_outreach, 'a call must not advance email engagement');
    }

    // ── Monotonicity: never regress ─────────────────────────────────────────────────────────────

    /**
     * `opened` is a fact about the RECIPIENT. Marking an email task done proves only that we sent
     * something, so pulling `opened` back to `sent` would destroy real information — and cost the
     * lead its Email Opened score signal. This case exists in live data today.
     */
    public function test_completing_an_email_never_regresses_opened(): void
    {
        $lead = $this->lead(['email_outreach' => 'opened']);

        $this->complete($this->event('email', $lead));

        $this->assertSame('opened', $lead->fresh()->email_outreach);
    }

    /** `replied` is written by InboundReplyProcessor from a real inbound message — strictly protected. */
    public function test_completing_an_email_never_regresses_replied(): void
    {
        $lead = $this->lead(['email_outreach' => 'replied']);

        $this->complete($this->event('email', $lead));

        $this->assertSame('replied', $lead->fresh()->email_outreach);
    }

    public function test_completing_an_email_is_a_no_op_when_already_sent(): void
    {
        $lead = $this->lead(['email_outreach' => 'sent']);

        $this->complete($this->event('email', $lead));

        $this->assertSame('sent', $lead->fresh()->email_outreach);
    }

    /** `called` is terminal for this listener: a second completed call changes nothing. */
    public function test_completing_a_call_never_regresses_called(): void
    {
        $lead = $this->lead(['cold_calling' => 'called']);

        $this->complete($this->event('call', $lead));

        $this->assertSame('called', $lead->fresh()->cold_calling);
    }

    /** Reopen → re-complete must stay idempotent rather than ratcheting anything further. */
    public function test_repeated_completion_is_idempotent(): void
    {
        $lead = $this->lead();
        $event = $this->event('call', $lead);

        $this->complete($event);
        $event->update(['status' => 'todo']);
        $this->complete($event);

        $this->assertSame('called', $lead->fresh()->cold_calling);
    }

    // ── Types that must NOT synchronize ─────────────────────────────────────────────────────────

    /**
     * `meeting` and `follow-up` are genuine follow-up work (they DO raise FollowUpCompleted), but
     * neither names a channel an engagement column models. `task` and `consultation` sit outside
     * FOLLOW_UP_WORK_TYPES entirely, so the event never even reaches the listener.
     */
    public function test_other_planner_types_leave_engagement_untouched(): void
    {
        foreach (['meeting', 'follow-up', 'task'] as $type) {
            $lead = $this->lead();

            $this->complete($this->event($type, $lead));

            $lead->refresh();
            $this->assertSame('not-called', $lead->cold_calling, "{$type} must not advance cold_calling");
            $this->assertSame('none', $lead->email_outreach, "{$type} must not advance email_outreach");
        }
    }

    // ── PLANNED != PERFORMED ────────────────────────────────────────────────────────────────────

    /** Scheduling, starting, or editing an event is not performing it. */
    public function test_non_completed_statuses_never_synchronize(): void
    {
        foreach (['todo', 'scheduled', 'in_progress'] as $status) {
            $lead = $this->lead();
            $event = $this->event('call', $lead, 'todo');

            $event->update(['status' => $status]);
            $event->update(['title' => 'renamed while still open']);

            $this->assertSame('not-called', $lead->fresh()->cold_calling, "status {$status} must not sync");
        }
    }

    // ── Boundaries the sync must respect ────────────────────────────────────────────────────────

    /** FROZEN (PO 2026-07-15): automated flows never mutate the SALES lifecycle. */
    public function test_the_sales_status_is_never_mutated(): void
    {
        $lead = $this->lead(['status' => 'new']);

        $this->complete($this->event('call', $lead));

        $this->assertSame('new', $lead->fresh()->status);
    }

    /** Explicit PO decision — unlike InboundReplyProcessor, this sync leaves recency alone. */
    public function test_last_activity_is_never_written(): void
    {
        $lead = $this->lead();
        $before = $lead->last_activity;

        $this->complete($this->event('call', $lead));

        $this->assertEquals($before, $lead->fresh()->last_activity);
    }

    /** A lead-less planner event must not explode — mirrors EscalateUnansweredFollowUp's guard. */
    public function test_a_lead_less_event_is_a_safe_no_op(): void
    {
        $event = PlannerEvent::factory()->create([
            'lead_id' => null,
            'assigned_to' => $this->bd->id,
            'event_type' => 'call',
            'status' => 'todo',
        ]);

        $this->complete($event);

        $this->assertSame('completed', $event->fresh()->status);
    }

    // ── Lead Score ──────────────────────────────────────────────────────────────────────────────

    /**
     * LeadScorer stays the sole writer; updateQuietly suppressed the observer, so we call it directly.
     *
     * The baseline is taken from the ENGINE, not from the column. LeadFactory seeds `score` with a
     * random 20–100, so reading it straight after create can compare against a number the scorer never
     * produced — which makes the assertion pass or fail on the factory's dice rather than on this
     * listener. Recomputing first turns it into a true before/after across one formula.
     */
    public function test_an_active_lead_is_rescored_after_the_sync(): void
    {
        $lead = $this->lead();
        app(LeadScorer::class)->refresh($lead);
        $before = (int) $lead->fresh()->score;

        $this->complete($this->event('call', $lead));

        $this->assertGreaterThan($before, (int) $lead->fresh()->score, 'Call Completed should raise the score');
    }

    /**
     * A discovered prospect carries no CRM score until a BD qualifies it in. The engagement column
     * still advances — only the scoring is withheld, mirroring LeadObserver and InboundReplyProcessor.
     */
    public function test_a_prospect_is_not_rescored(): void
    {
        $lead = $this->lead(['source' => 'google-maps', 'qualified_at' => null, 'score' => 0]);

        $this->complete($this->event('call', $lead));

        $lead->refresh();
        $this->assertSame('called', $lead->cold_calling, 'engagement still advances for a prospect');
        $this->assertSame(0, (int) $lead->score, 'a prospect must not gain a CRM score');
    }

    // ── The Calling Workspace path ──────────────────────────────────────────────────────────────

    /**
     * The existing Calling Workspace completes the planner event on a CONNECTED disposition
     * (CallSessionService), which routes through the SAME observer and event — so a real dial
     * advances engagement with no second code path. This is the case that closes the reported
     * "connected call, chip still says Not Called" defect.
     */
    public function test_a_connected_call_through_the_calling_workspace_advances_engagement(): void
    {
        $lead = $this->lead();
        $event = $this->event('call', $lead, 'in_progress');
        $call = Call::factory()->create([
            'lead_id' => $lead->id,
            'planner_event_id' => $event->id,
            'user_id' => $this->bd->id,
            'status' => 'in_progress',
            'outcome' => null,
        ]);

        app(CallSessionService::class)->complete($call, CallOutcome::CONNECTED, null, $this->bd);

        $this->assertSame('completed', $event->fresh()->status);
        $this->assertSame('called', $lead->fresh()->cold_calling);
    }

    /**
     * The mirror image: a disposition that does NOT complete the planner event must not advance
     * engagement either. CallSessionService leaves the event open so the BD can retry, and the sync
     * is bound to that same completion — one rule, not two.
     */
    public function test_a_non_connected_call_does_not_advance_engagement(): void
    {
        $lead = $this->lead();
        $event = $this->event('call', $lead, 'in_progress');
        $call = Call::factory()->create([
            'lead_id' => $lead->id,
            'planner_event_id' => $event->id,
            'user_id' => $this->bd->id,
            'status' => 'in_progress',
            'outcome' => null,
        ]);

        app(CallSessionService::class)->complete($call, CallOutcome::NO_ANSWER, null, $this->bd);

        $this->assertNotSame('completed', $event->fresh()->status);
        $this->assertSame('not-called', $lead->fresh()->cold_calling);
    }

    // ── The manual path stays free ──────────────────────────────────────────────────────────────

    /**
     * Monotonicity constrains the AUTOMATED write only. A BD correcting a value by hand — including
     * regressing it — must still win, and must keep winning until another event completes.
     */
    public function test_a_bd_can_still_manually_regress_engagement_after_a_sync(): void
    {
        $lead = $this->lead();
        $this->complete($this->event('call', $lead));
        $this->assertSame('called', $lead->fresh()->cold_calling);

        $this->actingAs($this->bd)
            ->patchJson("/api/v1/crm/leads/{$lead->id}", ['cold_calling' => 'not-called'])
            ->assertOk();

        $this->assertSame('not-called', $lead->fresh()->cold_calling);
    }
}
