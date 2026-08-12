<?php

namespace Tests\Feature\Crm;

use App\Models\User;
use App\Modules\Crm\Models\Email;
use App\Modules\Crm\Models\Lead;
use App\Modules\Crm\Models\PlannerEvent;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * NO-RESPONSE ESCALATION — {@see \App\Modules\Crm\Listeners\EscalateUnansweredFollowUp}.
 *
 * The FIRST direct coverage of this listener. Every pre-existing escalation test runs under
 * `Event::fake([FollowUpCompleted::class])` and asserts only that the event was dispatched, which is
 * the observer's contract, not the listener's — so what the listener actually DOES with it has never
 * been pinned. Nothing here fakes events: each test drives the real completion and then counts the
 * `source = 'system'` rows the listener did or did not create. That is the only way this slice's
 * defect is observable at all.
 *
 * The defect: Mark Lost force-completes the lead's whole open follow-up-work category, each close
 * fires FollowUpCompleted, and the listener handed the BD a high-priority "No response — follow up
 * with X" task (plus a reminder notification) for a lead they had just declared dead. It also left
 * that task open forever, which — via the idempotency guard — silently disabled all future escalation
 * for the lead.
 *
 * PLANNED != PERFORMED cuts both ways: nobody performed those follow-ups, so nothing may escalate
 * from them. `isLost()` reads the `lead.lost` Activity because Lost is deliberately not a lead column.
 */
class UnansweredFollowUpEscalationTest extends TestCase
{
    use RefreshDatabase;

    private User $bd;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->bd = User::factory()->create(['status' => 'active']);
        $this->bd->assignRole('crm_admin'); // sees every lead — keeps scoping out of these assertions
    }

    /**
     * `cold_calling` is pinned because LeadFactory randomises it and LeadObserver auto-creates a
     * cold-call follow-up on `scheduled`; `email_outreach` is pinned away from `replied` because that
     * alone would suppress escalation and make every assertion below vacuously pass.
     */
    private function lead(): Lead
    {
        return Lead::factory()->create([
            'assigned_to' => $this->bd->id,
            'created_by' => $this->bd->id,
            'cold_calling' => 'not-called',
            'email_outreach' => 'sent',
        ]);
    }

    private function event(string $type, Lead $lead): PlannerEvent
    {
        return PlannerEvent::create([
            'title' => "{$type} task",
            'event_type' => $type,
            'status' => 'todo',
            'lead_id' => $lead->id,
            'assigned_to' => $this->bd->id,
            'created_by' => $this->bd->id,
            'source' => 'manual',
        ]);
    }

    /** The escalation tasks the listener creates — `source = 'system'` is its signature. */
    private function escalations(Lead $lead): int
    {
        return PlannerEvent::query()
            ->where('lead_id', $lead->id)
            ->where('source', 'system')
            ->count();
    }

    // ── 1. The fix ──────────────────────────────────────────────────────────────────────────────

    /**
     * Mark Lost closes all four follow-up-work channels at once, so before the guard this produced an
     * escalation on the first close. Driven through the real endpoint, live listener.
     */
    public function test_marking_a_lead_lost_never_escalates(): void
    {
        $lead = $this->lead();
        foreach (['follow-up', 'email', 'call', 'meeting'] as $type) {
            $this->event($type, $lead);
        }

        $this->actingAs($this->bd)
            ->postJson("/api/v1/crm/leads/{$lead->id}/lost", ['reason' => 'budget'])
            ->assertCreated();

        $this->assertSame(
            0,
            $this->escalations($lead),
            'a lost lead must not be handed a "No response — follow up" task for work nobody performed',
        );
    }

    /**
     * The suppression is a property of the LEAD being lost, not of the endpoint: a follow-up completed
     * by any other path after the lead is lost must stay silent too. This is what makes the guard
     * durable rather than a special case wired into `lost()`.
     */
    public function test_a_later_completion_on_an_already_lost_lead_still_does_not_escalate(): void
    {
        $lead = $this->lead();

        $this->actingAs($this->bd)
            ->postJson("/api/v1/crm/leads/{$lead->id}/lost", ['reason' => 'budget'])
            ->assertCreated();

        $this->event('email', $lead)->update(['status' => 'completed']);

        $this->assertSame(0, $this->escalations($lead));
    }

    // ── 2. The guard must not over-fire ─────────────────────────────────────────────────────────

    /**
     * The control. Same completion, same absence of a reply, lead simply not lost — escalation MUST
     * still happen, or the fix would have silently disabled the whole feature.
     */
    public function test_a_completed_follow_up_on_a_live_lead_still_escalates(): void
    {
        $lead = $this->lead();

        $this->event('follow-up', $lead)->update(['status' => 'completed']);

        $this->assertSame(1, $this->escalations($lead));

        $task = PlannerEvent::query()->where('lead_id', $lead->id)->where('source', 'system')->sole();
        $this->assertSame('task', $task->event_type);
        $this->assertSame('high', $task->priority);
        $this->assertSame($this->bd->id, $task->assigned_to);
    }

    /**
     * `isLost()` must key on the `lead.lost` event specifically, not on "the lead has activities".
     *
     * SUPERSEDED EXPECTATION: this test previously used a `lead.won` Activity as its negative case and
     * asserted that it did NOT suppress escalation. That was true when Won closed nothing — it is no
     * longer the rule. Won now suppresses via {@see EscalateUnansweredFollowUp::isWon()}, so the
     * negative case had to become an activity with no outcome meaning at all. A bare `lead.won`
     * Activity with no Deal is still a valid probe of `isLost()` and is covered separately by
     * `test_the_won_guard_is_structural_not_activity_based`.
     */
    public function test_the_lost_guard_is_specific_to_the_lost_activity(): void
    {
        $lead = $this->lead();
        $lead->activities()->create([
            'causer_id' => $this->bd->id,
            'event' => 'lead.assigned',
            'description' => 'reassigned',
        ]);

        $this->event('follow-up', $lead)->update(['status' => 'completed']);

        $this->assertSame(1, $this->escalations($lead), 'only lead.lost may suppress via isLost()');
    }

    /**
     * The Won guard reads the DEAL, not the Activity — so an Activity written without a Deal (an
     * import artefact, a hand-written log line) cannot silently switch escalation off, and deleting a
     * Deal restores it. This is the property that makes `isWon()` self-correcting where `isLost()`,
     * bound to an append-only log, is not.
     */
    public function test_the_won_guard_is_structural_not_activity_based(): void
    {
        $lead = $this->lead();
        $lead->activities()->create([
            'causer_id' => $this->bd->id,
            'event' => 'lead.won',
            'description' => 'claims won, but no Deal exists',
        ]);

        $this->event('follow-up', $lead)->update(['status' => 'completed']);

        $this->assertSame(1, $this->escalations($lead), 'a lead.won Activity without a Deal is not won');
    }

    /** With a real Deal, the same completion is suppressed. */
    public function test_a_lead_with_a_deal_does_not_escalate(): void
    {
        $lead = $this->lead();
        $lead->deals()->create(['amount' => 50000, 'converted_at' => now()]);

        $this->event('follow-up', $lead)->update(['status' => 'completed']);

        $this->assertSame(0, $this->escalations($lead));
    }

    // ── 3. Pre-existing suppressions, unchanged ─────────────────────────────────────────────────

    /** An inbound email means the prospect answered — a good outcome, never an escalation. */
    public function test_a_lead_with_an_inbound_reply_does_not_escalate(): void
    {
        $lead = $this->lead();
        Email::create([
            'lead_id' => $lead->id,
            'user_id' => $this->bd->id,
            'direction' => 'inbound',
            'from_email' => 'prospect@example.test',
            'to_email' => 'business@viteseo.ph',
            'subject' => 'Re: hello',
            'body' => 'Interested.',
            'status' => 'received',
        ]);

        $this->event('follow-up', $lead)->update(['status' => 'completed']);

        $this->assertSame(0, $this->escalations($lead));
    }

    /** Idempotency: many completions, still one open escalation. */
    public function test_multiple_completions_still_produce_only_one_escalation(): void
    {
        $lead = $this->lead();

        $this->event('follow-up', $lead)->update(['status' => 'completed']);
        $this->event('email', $lead)->update(['status' => 'completed']);
        $this->event('call', $lead)->update(['status' => 'completed']);

        $this->assertSame(1, $this->escalations($lead));
    }

    // ── 4. The cleanup itself is untouched ──────────────────────────────────────────────────────

    /**
     * The guard suppresses the ESCALATION only. Mark Lost must still close every channel of open
     * follow-up work, and must still leave a plain `task` alone — the behaviour Phase 1 established.
     * Asserted here under a live listener, where FollowUpWorkTest's equivalent runs the same path.
     */
    public function test_marking_a_lead_lost_still_closes_every_channel(): void
    {
        $lead = $this->lead();
        foreach (['follow-up', 'email', 'call', 'meeting'] as $type) {
            $this->event($type, $lead);
        }
        $survivor = $this->event('task', $lead);

        $this->actingAs($this->bd)
            ->postJson("/api/v1/crm/leads/{$lead->id}/lost", ['reason' => 'budget'])
            ->assertCreated();

        $this->assertSame(
            0,
            PlannerEvent::query()->where('lead_id', $lead->id)
                ->followUpWork()->where('status', '!=', 'completed')->count(),
            'a lost lead is owed no further work through any channel',
        );
        $this->assertSame('todo', $survivor->fresh()->status, 'a generic task is not follow-up work');
    }
}
