<?php

namespace Tests\Feature\Crm;

use App\Models\User;
use App\Modules\Crm\Models\Lead;
use App\Modules\Crm\Models\PlannerEvent;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * MARK WON — closing sales-chasing work ({@see PlannerEvent::WON_CLOSES_TYPES}).
 *
 * Won used to close nothing at all, so a signed customer kept its open follow-ups forever and went on
 * counting against its BD as overdue work in `followUpsDue` / `missedFollowUps` / `missedCalls`. Live
 * data at the time of the fix: 10 won leads, 4 still carrying open follow-up work, 3 of it overdue.
 *
 * The rule is deliberately NARROWER than Mark Lost, and the difference is the whole point:
 *   LOST → owed nothing, so the entire follow-up-work category closes, `meeting` included.
 *   WON  → the pursuit ends, the relationship starts. Chasing (`follow-up`/`email`/`call`) closes;
 *          `meeting` stays open because a won deal is exactly when onboarding is booked. `task` and
 *          `consultation` were never in the category.
 *
 * Nothing here fakes events. Every completion runs the real observer and the real
 * EscalateUnansweredFollowUp, because the second half of this rule is that Won must not escalate —
 * mirroring Lost naively would hand the BD "No response — follow up with X" for a customer they just
 * signed. Under `Event::fake` that defect is invisible, which is precisely how it survived before.
 */
class WonLeadClosesChasingWorkTest extends TestCase
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
     * `cold_calling` is pinned because LeadFactory randomises it and LeadObserver auto-creates a
     * cold-call follow-up on `scheduled`, which would add an event these counts do not expect.
     * `email_outreach` is kept off `replied` so escalation is not suppressed for the wrong reason —
     * the Won guard must be what silences it, not a stray reply flag.
     */
    private function lead(): Lead
    {
        return Lead::factory()->create([
            'assigned_to' => $this->bd->id,
            'created_by' => $this->bd->id,
            'cold_calling' => 'not-called',
            'email_outreach' => 'sent',
            'amount' => 50000,
        ]);
    }

    private function event(string $type, Lead $lead): PlannerEvent
    {
        return PlannerEvent::create([
            'title' => "{$type} work",
            'event_type' => $type,
            'status' => 'todo',
            'lead_id' => $lead->id,
            'assigned_to' => $this->bd->id,
            'created_by' => $this->bd->id,
            'source' => 'manual',
        ]);
    }

    private function markWon(Lead $lead): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->bd)->postJson("/api/v1/crm/leads/{$lead->id}/won", []);
    }

    // ── What Won closes ─────────────────────────────────────────────────────────────────────────

    /** @return array<string, array{0: string}> */
    public static function chasingChannels(): array
    {
        return ['follow-up' => ['follow-up'], 'email' => ['email'], 'call' => ['call']];
    }

    #[DataProvider('chasingChannels')]
    public function test_won_closes_each_chasing_channel(string $type): void
    {
        $lead = $this->lead();
        $event = $this->event($type, $lead);

        $this->markWon($lead)->assertCreated();

        $this->assertSame('completed', $event->fresh()->status);
    }

    // ── What Won must NOT close ─────────────────────────────────────────────────────────────────

    /**
     * THE post-sale protection, and the reason `WON_CLOSES_TYPES` exists as its own constant instead
     * of reusing `followUpWork()`. A kickoff meeting booked around signing must survive. If someone
     * later "tidies up" by pointing Won at the shared category, this is the test that stops them.
     */
    public function test_won_keeps_meeting_open(): void
    {
        $lead = $this->lead();
        $meeting = $this->event('meeting', $lead);

        $this->markWon($lead)->assertCreated();

        $this->assertSame('todo', $meeting->fresh()->status, 'a won deal is when onboarding is booked');
    }

    /** @return array<string, array{0: string}> */
    public static function survivingTypes(): array
    {
        return ['meeting' => ['meeting'], 'task' => ['task'], 'consultation' => ['consultation']];
    }

    #[DataProvider('survivingTypes')]
    public function test_won_leaves_post_sale_work_open(string $type): void
    {
        $lead = $this->lead();
        $event = $this->event($type, $lead);

        $this->markWon($lead)->assertCreated();

        $this->assertSame('todo', $event->fresh()->status);
    }

    /** The whole rule in one pass — the mixed board a real lead actually carries. */
    public function test_won_closes_only_chasing_work_on_a_mixed_board(): void
    {
        $lead = $this->lead();
        $events = [];
        foreach (['follow-up', 'email', 'call', 'meeting', 'task', 'consultation'] as $type) {
            $events[$type] = $this->event($type, $lead);
        }

        $this->markWon($lead)->assertCreated();

        $actual = array_map(fn (PlannerEvent $e): string => $e->fresh()->status, $events);

        $this->assertSame([
            'follow-up' => 'completed',
            'email' => 'completed',
            'call' => 'completed',
            'meeting' => 'todo',
            'task' => 'todo',
            'consultation' => 'todo',
        ], $actual);
    }

    // ── Won must not escalate ───────────────────────────────────────────────────────────────────

    /**
     * The half of this slice that is easy to forget. Closing three chasing events fires three
     * FollowUpCompleted events; without `isWon()` the first one mints a high-priority "No response —
     * follow up with X" task for a customer who just signed.
     */
    public function test_won_never_creates_an_escalation_task(): void
    {
        $lead = $this->lead();
        foreach (['follow-up', 'email', 'call'] as $type) {
            $this->event($type, $lead);
        }

        $this->markWon($lead)->assertCreated();

        $this->assertSame(
            0,
            PlannerEvent::query()->where('lead_id', $lead->id)->where('source', 'system')->count(),
            'a signed customer must never be escalated as unresponsive',
        );
    }

    /**
     * Ordering guard. `won()` creates the Deal BEFORE closing the work precisely so `isWon()` can see
     * it; reversing those two statements would reintroduce the escalation. Asserted through the
     * observable outcome — the Deal exists and nothing escalated in the same request.
     */
    public function test_the_deal_exists_before_the_work_is_closed(): void
    {
        $lead = $this->lead();
        $this->event('call', $lead);

        $this->markWon($lead)->assertCreated();

        $this->assertSame(1, $lead->deals()->count());
        $this->assertSame(0, PlannerEvent::query()->where('lead_id', $lead->id)->where('source', 'system')->count());
    }

    // ── Existing behaviour that must not regress ────────────────────────────────────────────────

    /** The duplicate-Deal guard still short-circuits, and a second call closes nothing new. */
    public function test_a_second_won_is_rejected_and_changes_nothing(): void
    {
        $lead = $this->lead();
        $this->markWon($lead)->assertCreated();

        $meeting = $this->event('meeting', $lead);
        $call = $this->event('call', $lead);

        $this->markWon($lead)->assertStatus(409);

        $this->assertSame(1, $lead->deals()->count());
        $this->assertSame('todo', $call->fresh()->status, 'the rejected request must not close work');
        $this->assertSame('todo', $meeting->fresh()->status);
    }

    /** Won writes its Deal and Activity but still never touches the sales lifecycle column. */
    public function test_won_does_not_change_the_lead_status(): void
    {
        $lead = $this->lead();
        $before = $lead->status;

        $this->markWon($lead)->assertCreated();

        $this->assertSame($before, $lead->fresh()->status);
    }

    /**
     * Lost is unchanged and still closes the WIDER category — `meeting` included. Kept here beside
     * the Won rule so the asymmetry is visible in one file rather than inferred across two.
     */
    public function test_lost_still_closes_meetings_unlike_won(): void
    {
        $lead = $this->lead();
        $meeting = $this->event('meeting', $lead);

        $this->actingAs($this->bd)
            ->postJson("/api/v1/crm/leads/{$lead->id}/lost", ['reason' => 'budget'])
            ->assertCreated();

        $this->assertSame('completed', $meeting->fresh()->status, 'a lost lead is owed nothing at all');
    }
}
