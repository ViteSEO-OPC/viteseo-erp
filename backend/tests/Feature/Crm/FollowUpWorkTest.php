<?php

namespace Tests\Feature\Crm;

use App\Models\User;
use App\Modules\Crm\Events\FollowUpCompleted;
use App\Modules\Crm\Models\Lead;
use App\Modules\Crm\Models\PlannerEvent;
use App\Modules\Crm\Services\Performance\PerformanceSummaryService;
use App\Modules\Crm\Services\Planner\FollowUpScheduler;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/**
 * FOLLOW-UP WORK — the canonical business category ({@see PlannerEvent::FOLLOW_UP_WORK_TYPES}).
 *
 * Phase 1 of the PO-approved Model B: `event_type` stores the CHANNEL, and "follow-up work" is the
 * category spanning `follow-up | email | call | meeting`. Every consumer that means the category now
 * says so, instead of matching the bare string `'follow-up'` — which was only ever correct while Lead
 * Details discarded the BD's channel choice.
 *
 * These tests pin the boundary in both directions: what the category must include (so nothing
 * disappears when channels are routed structurally in Phase 2), and what it must keep OUT — `task`,
 * `consultation`, and the creation-time paths that ask a deliberately narrower question.
 */
class FollowUpWorkTest extends TestCase
{
    use RefreshDatabase;

    private User $bd;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->bd = User::factory()->create(['status' => 'active']);
        $this->bd->assignRole('crm_admin'); // sees every event — keeps scoping out of these assertions
    }

    /**
     * `cold_calling` is pinned because LeadFactory randomises it, and LeadObserver auto-creates a
     * cold-call follow-up on `scheduled` — which would silently add an event to these counts.
     */
    private function lead(): Lead
    {
        return Lead::factory()->create([
            'assigned_to' => $this->bd->id,
            'source' => null,
            'cold_calling' => 'not-called',
            'email_outreach' => 'none',
        ]);
    }

    /**
     * `completed_at` is owned by PlannerEventObserver (stamped on the transition into `completed`,
     * cleared on reopen) and is not fillable, so a test that needs a specific timestamp must write it
     * without re-triggering the observer.
     */
    private function completedAt(PlannerEvent $event, ?string $when): PlannerEvent
    {
        $event->forceFill(['completed_at' => $when])->saveQuietly();

        return $event;
    }

    /** @param array<string, mixed> $attributes */
    private function event(string $type, array $attributes = []): PlannerEvent
    {
        return PlannerEvent::create([
            'title' => "{$type} task",
            'event_type' => $type,
            'status' => 'todo',
            'assigned_to' => $this->bd->id,
            ...$attributes,
        ]);
    }

    // ── The predicate itself ────────────────────────────────────────────────────────────────────

    /** @return list<array{0: string}> */
    public static function followUpWorkTypes(): array
    {
        return [['follow-up'], ['email'], ['call'], ['meeting']];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('followUpWorkTypes')]
    public function test_every_channel_of_follow_up_work_is_included(string $type): void
    {
        $event = $this->event($type);

        $this->assertTrue(
            PlannerEvent::query()->followUpWork()->whereKey($event->getKey())->exists(),
            "{$type} is work a BD owes a lead and must belong to the category",
        );
    }

    /** @return list<array{0: string}> */
    public static function excludedTypes(): array
    {
        return [['task'], ['consultation']];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('excludedTypes')]
    public function test_task_and_consultation_are_excluded(string $type): void
    {
        $event = $this->event($type);

        $this->assertFalse(
            PlannerEvent::query()->followUpWork()->whereKey($event->getKey())->exists(),
            "{$type} has its own meaning and must not be counted as follow-up work",
        );
    }

    public function test_the_scope_accepts_an_explicit_subset_for_disjoint_buckets(): void
    {
        $this->event('follow-up');
        $this->event('email');
        $this->event('call');
        $this->event('meeting');

        $this->assertSame(4, PlannerEvent::query()->followUpWork()->count());
        $this->assertSame(
            2,
            PlannerEvent::query()->followUpWork(PlannerEvent::MISSED_FOLLOW_UP_TYPES)->count(),
            'the missed slice is follow-up + email only',
        );
    }

    /** The legacy member must stay in the category so historical rows keep counting. */
    public function test_generic_follow_up_remains_part_of_the_category(): void
    {
        $this->assertContains('follow-up', PlannerEvent::FOLLOW_UP_WORK_TYPES);
    }

    // ── Completed Follow-ups ────────────────────────────────────────────────────────────────────

    public function test_completed_follow_ups_counts_every_channel_this_month(): void
    {
        foreach (['follow-up', 'email', 'call', 'meeting'] as $type) {
            $this->event($type, ['status' => 'completed']);
        }
        // Excluded types completed in the same window must not inflate it.
        $this->event('task', ['status' => 'completed']);
        $this->event('consultation', ['status' => 'completed']);

        $this->assertSame(4, app(PerformanceSummaryService::class)->for($this->bd)['completedFollowUps']);
    }

    public function test_completed_follow_ups_still_ignores_other_months_and_null_timestamps(): void
    {
        $this->completedAt($this->event('email', ['status' => 'completed']), now()->subMonth()->toDateTimeString());
        $this->completedAt($this->event('call', ['status' => 'completed']), null); // pre-`completed_at` history
        $this->event('follow-up', ['status' => 'completed']);

        $this->assertSame(
            1,
            app(PerformanceSummaryService::class)->for($this->bd)['completedFollowUps'],
            'the window and the completed_at rule are unchanged — only the type set moved',
        );
    }

    // ── Missed buckets stay a partition ─────────────────────────────────────────────────────────

    public function test_an_overdue_email_task_is_now_missed_follow_up_work(): void
    {
        $this->event('email', ['starts_at' => now()->subDay()]);

        $discipline = app(PerformanceSummaryService::class)->for($this->bd)['followUpDiscipline'];

        $this->assertSame(1, $discipline['missedFollowUps'], 'an overdue Email task was previously counted nowhere');
        $this->assertSame(0, $discipline['missedCalls']);
        $this->assertSame(0, $discipline['missedMeetings']);
    }

    public function test_overdue_calls_and_meetings_stay_in_their_own_buckets_and_are_never_double_counted(): void
    {
        $this->event('follow-up', ['starts_at' => now()->subDay()]);
        $this->event('email', ['starts_at' => now()->subDay()]);
        $this->event('call', ['starts_at' => now()->subDay()]);
        $this->event('meeting', ['starts_at' => now()->subDay()]);

        $discipline = app(PerformanceSummaryService::class)->for($this->bd)['followUpDiscipline'];

        $this->assertSame(2, $discipline['missedFollowUps'], 'follow-up + email only');
        $this->assertSame(1, $discipline['missedCalls'], 'channel-specific reporting preserved');
        $this->assertSame(1, $discipline['missedMeetings'], 'channel-specific reporting preserved');
        $this->assertSame(
            4,
            array_sum($discipline),
            'the three buckets must partition overdue follow-up work — no row counted twice',
        );
    }

    public function test_the_missed_timing_rule_is_unchanged(): void
    {
        $this->event('email', ['starts_at' => now()->addDay()]);                          // not yet due
        $this->event('email', ['starts_at' => null]);                                     // undated backlog
        $this->event('email', ['starts_at' => now()->subDay(), 'status' => 'completed']);  // done in time

        $this->assertSame(0, app(PerformanceSummaryService::class)->for($this->bd)['followUpDiscipline']['missedFollowUps']);
    }

    // ── Dashboard pending follow-ups ────────────────────────────────────────────────────────────

    public function test_dashboard_pending_follow_ups_covers_every_channel(): void
    {
        foreach (['follow-up', 'email', 'call', 'meeting'] as $type) {
            $this->event($type, ['starts_at' => now()->addDay()]);
        }
        $this->event('task', ['starts_at' => now()->addDay()]);
        $this->event('email', ['starts_at' => null]);                                    // undated: excluded
        $this->event('email', ['starts_at' => now()->addDay(), 'status' => 'completed']); // done: excluded

        $payload = $this->actingAs($this->bd)->getJson('/api/v1/crm/dashboard/kpis')
            ->assertOk()->json('data');

        $this->assertSame(4, $payload['followUpsDue'], 'the whole category, minus task/undated/completed');
    }

    // ── Mark Lost / Won auto-close ──────────────────────────────────────────────────────────────

    public function test_marking_a_lead_lost_closes_open_work_on_every_channel(): void
    {
        $lead = $this->lead();
        foreach (['follow-up', 'email', 'call', 'meeting'] as $type) {
            $this->event($type, ['lead_id' => $lead->id]);
        }
        $survivor = $this->event('task', ['lead_id' => $lead->id]);

        $this->actingAs($this->bd)->postJson("/api/v1/crm/leads/{$lead->id}/lost", ['reason' => 'budget'])
            ->assertCreated();

        $this->assertSame(
            0,
            PlannerEvent::query()->where('lead_id', $lead->id)->followUpWork()->where('status', '!=', 'completed')->count(),
            'a lost lead is owed no further work through any channel',
        );
        $this->assertSame('todo', $survivor->fresh()->status, 'a generic task is not follow-up work and stays open');
    }

    public function test_the_converted_reply_path_closes_open_work_on_every_channel(): void
    {
        $lead = $this->lead();
        foreach (['follow-up', 'email', 'call', 'meeting'] as $type) {
            $this->event($type, ['lead_id' => $lead->id]);
        }
        $survivor = $this->event('consultation', ['lead_id' => $lead->id]);

        $closed = app(FollowUpScheduler::class)->completeOpenFor($lead);

        $this->assertSame(4, $closed);
        $this->assertSame('todo', $survivor->fresh()->status, 'a consultation is a milestone, not follow-up work');
    }

    // ── FollowUpCompleted / No-Response Escalation ──────────────────────────────────────────────

    /** @return list<array{0: string}> */
    public static function escalationEligibleTypes(): array
    {
        return [['follow-up'], ['email'], ['call'], ['meeting']];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('escalationEligibleTypes')]
    public function test_completing_follow_up_work_on_any_channel_stays_escalation_eligible(string $type): void
    {
        Event::fake([FollowUpCompleted::class]);
        $event = $this->event($type, ['lead_id' => $this->lead()->id]);

        $event->update(['status' => 'completed']);

        Event::assertDispatched(FollowUpCompleted::class);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('excludedTypes')]
    public function test_completing_an_excluded_type_does_not_escalate(string $type): void
    {
        Event::fake([FollowUpCompleted::class]);
        $event = $this->event($type, ['lead_id' => $this->lead()->id]);

        $event->update(['status' => 'completed']);

        Event::assertNotDispatched(FollowUpCompleted::class);
    }

    /**
     * PLANNED != PERFORMED. Scheduling an Email task — or moving it through any pre-completion status
     * — must never be mistaken for having done the work.
     */
    public function test_scheduling_alone_never_triggers_completion_or_escalation(): void
    {
        Event::fake([FollowUpCompleted::class]);
        $event = $this->event('email', ['lead_id' => $this->lead()->id, 'starts_at' => now()->addDay()]);

        $event->update(['status' => 'scheduled']);
        $event->update(['status' => 'in_progress']);

        Event::assertNotDispatched(FollowUpCompleted::class);
        $this->assertNotSame('completed', $event->fresh()->status);
    }

    // ── The creation-time guard must NOT adopt the category ─────────────────────────────────────

    /**
     * The cold-call follow-up exists precisely BECAUSE a call just happened, and that call leaves an
     * OPEN `call` event behind. If the dedupe guard widened to the shared category it would see that
     * event and suppress the follow-up.
     *
     * The guard has since been allowed to see one further shape — a call that is `scheduled` AND
     * dated, which the Calling lifecycle never produces — but this in-progress case must stay
     * excluded forever. See FollowUpScheduler::coldCallFollowUpIsRedundant() and the edge tests in
     * LeadFollowUpChannelRoutingTest.
     */
    public function test_an_open_call_event_does_not_suppress_the_cold_call_follow_up(): void
    {
        $lead = $this->lead();
        $this->event('call', ['lead_id' => $lead->id, 'status' => 'in_progress']);

        $created = app(FollowUpScheduler::class)->scheduleForColdCall($lead);

        $this->assertNotNull($created, 'the guard must not be blinded by the call that triggered it');
        $this->assertSame('follow-up', $created->event_type);
    }

    public function test_an_open_generic_follow_up_still_suppresses_a_duplicate(): void
    {
        $lead = $this->lead();
        $this->event('follow-up', ['lead_id' => $lead->id]);

        $this->assertNull(
            app(FollowUpScheduler::class)->scheduleForColdCall($lead),
            'the existing dedupe behaviour is unchanged',
        );
    }
}
