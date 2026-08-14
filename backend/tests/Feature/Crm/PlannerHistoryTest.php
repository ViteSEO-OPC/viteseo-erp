<?php

namespace Tests\Feature\Crm;

use App\Models\User;
use App\Modules\Crm\Models\Lead;
use App\Modules\Crm\Models\PlannerEvent;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Planner History (`GET /planner-events?view=history`) — the third Planner mode.
 *
 * Completed work was never deleted; it was only retired from the live Planner by the rolling 7-day
 * window in PlannerEventController::applyCompletedWindow(). History is the view that reaches past
 * that window: the SAME endpoint, the SAME ownership scope, the SAME policy — a different question
 * asked of the same rows, not a second Planner and not a second table.
 *
 * These tests pin both halves of that claim: History reaches the old completions, AND the live
 * Planner is completely unchanged by its existence (window, KPI safety, and the consultation
 * exemption all behave exactly as before).
 */
class PlannerHistoryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    private function user(string $role): User
    {
        $user = User::factory()->create(['status' => 'active']);
        $user->assignRole($role);

        return $user;
    }

    private function lead(?User $owner): Lead
    {
        return Lead::factory()->create([
            'assigned_to' => $owner?->id,
            'cold_calling' => 'not-called',
        ]);
    }

    /**
     * Creates an event, forcing an exact `completed_at` when given.
     *
     * `completed_at` is deliberately absent from PlannerEvent's #[Fillable] — the observer is its
     * only writer — so it is set as an attribute and saved quietly, which also stops the observer
     * re-stamping it to now(). Mirrors the helper in PlannerCompletedWindowTest.
     */
    private function event(
        User $owner,
        ?Lead $lead,
        string $status = 'completed',
        ?Carbon $completedAt = null,
        string $type = 'follow-up',
        ?string $outcome = null,
        string $title = 'Work',
    ): PlannerEvent {
        $event = PlannerEvent::create([
            'title' => $title,
            'event_type' => $type,
            'status' => $status,
            'outcome' => $outcome,
            'lead_id' => $lead?->id,
            'assigned_to' => $owner->id,
            'created_by' => $owner->id,
        ]);

        if ($status === 'completed') {
            $event->completed_at = $completedAt;
            $event->saveQuietly();
        }

        return $event->fresh();
    }

    /** @return list<string> planner event ids the caller's Planner/History returns */
    private function ids(User $user, array $params = []): array
    {
        Sanctum::actingAs($user);

        return $this->getJson('/api/v1/crm/planner-events?'.http_build_query($params))
            ->assertOk()
            ->json('data.*.id');
    }

    /** @return list<string> */
    private function historyIds(User $user, array $params = []): array
    {
        return $this->ids($user, ['view' => 'history'] + $params);
    }

    // ────────────────────────────────────────────────── 1. a BD's own History

    public function test_a_bd_sees_their_own_completed_work_beyond_the_seven_day_window(): void
    {
        $bd = $this->user('crm_bd');
        $old = $this->event($bd, $this->lead($bd), 'completed', now()->subMonths(4));
        $recent = $this->event($bd, $this->lead($bd), 'completed', now()->subDays(2));

        $history = $this->historyIds($bd);

        // The whole point: the old completion is unreachable on the live Planner but present here.
        $this->assertNotContains((string) $old->id, $this->ids($bd));
        $this->assertContains((string) $old->id, $history);
        $this->assertContains((string) $recent->id, $history);
    }

    public function test_history_carries_the_fields_that_make_a_completion_traceable(): void
    {
        $bd = $this->user('crm_bd');
        $lead = $this->lead($bd);
        $event = $this->event($bd, $lead, 'completed', now()->subMonths(2), title: 'Quarterly check-in');

        Sanctum::actingAs($bd);
        $row = $this->getJson('/api/v1/crm/planner-events?view=history')
            ->assertOk()
            ->json('data.0');

        $this->assertSame('Quarterly check-in', $row['title']);
        $this->assertSame('completed', $row['status']);
        $this->assertSame((string) $bd->id, $row['assignedToId']);
        $this->assertSame($bd->name, $row['owner']['name']);      // owner / BD
        $this->assertSame($lead->business_name, $row['lead']['business']);
        $this->assertNotNull($row['createdAt']);                   // created date
        $this->assertNotNull($row['completedAt']);                 // completed date
        $this->assertSame($event->completed_at->toIso8601String(), $row['completedAt']);
    }

    public function test_history_is_ordered_by_most_recently_completed(): void
    {
        $bd = $this->user('crm_bd');
        $oldest = $this->event($bd, null, 'completed', now()->subMonths(6));
        $newest = $this->event($bd, null, 'completed', now()->subDays(1));
        $middle = $this->event($bd, null, 'completed', now()->subMonths(2));

        $this->assertSame(
            [(string) $newest->id, (string) $middle->id, (string) $oldest->id],
            $this->historyIds($bd),
        );
    }

    // ────────────────────────────────────────────────── 2. a BD cannot reach another BD's History

    public function test_a_bd_never_sees_another_bds_completed_work_in_history(): void
    {
        $bd = $this->user('crm_bd');
        $other = $this->user('crm_bd');
        $theirs = $this->event($other, $this->lead($other), 'completed', now()->subMonths(3));
        $mine = $this->event($bd, $this->lead($bd), 'completed', now()->subMonths(3));

        $history = $this->historyIds($bd);

        $this->assertNotContains((string) $theirs->id, $history);
        $this->assertContains((string) $mine->id, $history);
    }

    /**
     * THE FILTER IS NOT AN AUTHORIZATION HOLE. `assigned_to` is applied after scopeVisibleTo, so a BD
     * aiming it at another BD intersects with their own events and gets nothing — no leak, and no
     * disclosure of whether that BD has any work at all.
     */
    public function test_a_bd_cannot_use_the_assigned_to_filter_to_reach_another_bds_history(): void
    {
        $bd = $this->user('crm_bd');
        $other = $this->user('crm_bd');
        $theirs = $this->event($other, $this->lead($other), 'completed', now()->subMonths(3));

        $history = $this->historyIds($bd, ['assigned_to' => $other->id]);

        $this->assertSame([], $history);
        $this->assertNotContains((string) $theirs->id, $history);
        // Still on disk and still visible to its real owner — filtered, never deleted.
        $this->assertDatabaseHas('crm_planner_events', ['id' => $theirs->id, 'deleted_at' => null]);
        $this->assertContains((string) $theirs->id, $this->historyIds($other));
    }

    public function test_the_same_holds_for_the_live_planner_filter(): void
    {
        $bd = $this->user('crm_bd');
        $other = $this->user('crm_bd');
        $theirs = $this->event($other, $this->lead($other), 'todo');

        $this->assertSame([], $this->ids($bd, ['assigned_to' => $other->id]));
        $this->assertNotContains((string) $theirs->id, $this->ids($bd));
    }

    // ────────────────────────────────────────────────── 3 & 4. Admin: all BDs, and one BD

    public function test_an_admin_sees_every_bds_history(): void
    {
        $admin = $this->user('crm_admin');
        $first = $this->user('crm_bd');
        $second = $this->user('crm_bd');
        $a = $this->event($first, $this->lead($first), 'completed', now()->subMonths(5));
        $b = $this->event($second, $this->lead($second), 'completed', now()->subMonths(5));

        $history = $this->historyIds($admin);

        $this->assertContains((string) $a->id, $history);
        $this->assertContains((string) $b->id, $history);
    }

    public function test_an_admin_can_narrow_history_to_one_bd(): void
    {
        $admin = $this->user('crm_admin');
        $first = $this->user('crm_bd');
        $second = $this->user('crm_bd');
        $a = $this->event($first, $this->lead($first), 'completed', now()->subMonths(5));
        $b = $this->event($second, $this->lead($second), 'completed', now()->subMonths(5));

        $history = $this->historyIds($admin, ['assigned_to' => $first->id]);

        $this->assertContains((string) $a->id, $history);
        $this->assertNotContains((string) $b->id, $history);
    }

    public function test_a_super_admin_sees_every_bds_history(): void
    {
        $superAdmin = $this->user('super_admin');
        $bd = $this->user('crm_bd');
        $event = $this->event($bd, $this->lead($bd), 'completed', now()->subMonths(5));

        $this->assertContains((string) $event->id, $this->historyIds($superAdmin));
    }

    // ────────────────────────────────────────────────── History is completed-only

    public function test_history_never_returns_open_work(): void
    {
        $bd = $this->user('crm_bd');
        $open = $this->event($bd, $this->lead($bd), 'todo');
        $inProgress = $this->event($bd, $this->lead($bd), 'in_progress');
        $done = $this->event($bd, $this->lead($bd), 'completed', now()->subMonths(2));

        $this->assertSame([(string) $done->id], $this->historyIds($bd));
        $this->assertNotContains((string) $open->id, $this->historyIds($bd));
        $this->assertNotContains((string) $inProgress->id, $this->historyIds($bd));
    }

    /** `?status` is ignored in History — "completed" is what the mode means. */
    public function test_a_status_filter_cannot_turn_history_into_open_work(): void
    {
        $bd = $this->user('crm_bd');
        $open = $this->event($bd, $this->lead($bd), 'todo');
        $done = $this->event($bd, $this->lead($bd), 'completed', now()->subMonths(2));

        $history = $this->historyIds($bd, ['status' => 'todo']);

        $this->assertSame([(string) $done->id], $history);
        $this->assertNotContains((string) $open->id, $history);
    }

    public function test_an_unknown_view_is_rejected_rather_than_silently_ignored(): void
    {
        Sanctum::actingAs($this->user('crm_bd'));

        $this->getJson('/api/v1/crm/planner-events?view=archive')->assertStatus(422);
        $this->getJson('/api/v1/crm/planner-events?assigned_to=not-a-user')->assertStatus(422);
    }

    // ────────────────────────────────────────────────── 5. the live Planner is unchanged

    public function test_the_seven_day_board_window_is_unaffected_by_history_existing(): void
    {
        $bd = $this->user('crm_bd');
        $recent = $this->event($bd, $this->lead($bd), 'completed', now()->subDays(6));
        $old = $this->event($bd, $this->lead($bd), 'completed', now()->subDays(8));

        $planner = $this->ids($bd);

        $this->assertContains((string) $recent->id, $planner);
        $this->assertNotContains((string) $old->id, $planner);

        // …and History is exactly where the retired one went.
        $this->assertContains((string) $old->id, $this->historyIds($bd));
    }

    public function test_an_admin_is_still_exempt_from_the_window_on_the_live_planner(): void
    {
        $admin = $this->user('crm_admin');
        $bd = $this->user('crm_bd');
        $old = $this->event($bd, $this->lead($bd), 'completed', now()->subDays(90));

        $this->assertContains((string) $old->id, $this->ids($admin));
    }

    public function test_open_work_is_untouched_on_the_live_planner(): void
    {
        $bd = $this->user('crm_bd');
        $todo = $this->event($bd, $this->lead($bd), 'todo');
        $scheduled = $this->event($bd, $this->lead($bd), 'scheduled');

        $planner = $this->ids($bd);

        $this->assertContains((string) $todo->id, $planner);
        $this->assertContains((string) $scheduled->id, $planner);
    }

    // ────────────────────────────────────────────────── 6. consultation-without-outcome

    /**
     * The load-bearing exemption, unchanged: the Board card is the only BD-reachable place to record
     * a consultation outcome, so a consultation still owed one never expires off the live Planner —
     * however old it is. History showing it too must not disturb that.
     */
    public function test_a_consultation_without_an_outcome_still_never_expires_from_the_planner(): void
    {
        $bd = $this->user('crm_bd');
        $awaiting = $this->event(
            $bd, $this->lead($bd), 'completed', now()->subMonths(6), type: 'consultation', outcome: null,
        );

        $this->assertContains((string) $awaiting->id, $this->ids($bd));
    }

    public function test_a_consultation_with_an_outcome_still_expires_normally(): void
    {
        $bd = $this->user('crm_bd');
        $concluded = $this->event(
            $bd, $this->lead($bd), 'completed', now()->subMonths(6), type: 'consultation', outcome: 'attended',
        );

        $this->assertNotContains((string) $concluded->id, $this->ids($bd));
        $this->assertContains((string) $concluded->id, $this->historyIds($bd));
    }

    public function test_history_includes_consultations_in_both_outcome_states(): void
    {
        $bd = $this->user('crm_bd');
        $awaiting = $this->event(
            $bd, $this->lead($bd), 'completed', now()->subMonths(6), type: 'consultation', outcome: null,
        );
        $concluded = $this->event(
            $bd, $this->lead($bd), 'completed', now()->subMonths(5), type: 'consultation', outcome: 'attended',
        );

        $history = $this->historyIds($bd);

        $this->assertContains((string) $awaiting->id, $history);
        $this->assertContains((string) $concluded->id, $history);
    }
}
