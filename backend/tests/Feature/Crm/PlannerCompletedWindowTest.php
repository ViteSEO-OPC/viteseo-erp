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
 * Completed Planner work retires from a BD's Planner 7 days after `completed_at`.
 *
 * The Planner answers "what do I owe, and what did I just finish?" — an old completion is history and
 * belongs to reporting and the lead timeline, both of which still hold every row. Nothing is deleted.
 *
 * The window is applied in PlannerEventController::index() ONLY. These tests therefore also guard the
 * boundary that makes the fix safe: KPI endpoints read completed events through
 * PlannerEvent::scopeVisibleTo with their own periods, and must not see the 7-day window at all.
 */
class PlannerCompletedWindowTest extends TestCase
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
     * Creates an event and, when $completedAt is given, forces that exact timestamp.
     *
     * The second write is deliberate: PlannerEventObserver stamps `completed_at = now()` on the
     * transition into `completed`, so an "old" completion can only be produced by overwriting it
     * afterwards.
     *
     * It sets the attribute DIRECTLY rather than via update(): `completed_at` is intentionally absent
     * from PlannerEvent's #[Fillable] list — the observer is its only writer — so a mass-assignment
     * write is silently discarded. saveQuietly then keeps the observer from re-stamping it to now().
     */
    private function event(
        User $owner,
        ?Lead $lead,
        string $status = 'completed',
        ?Carbon $completedAt = null,
        string $type = 'follow-up',
        ?string $outcome = null,
    ): PlannerEvent {
        $event = PlannerEvent::create([
            'title' => 'Work',
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

    /** @return list<string> planner event ids the caller's Planner returns */
    private function plannerIds(User $user, array $params = []): array
    {
        Sanctum::actingAs($user);

        return $this->getJson('/api/v1/crm/planner-events?'.http_build_query($params))
            ->assertOk()
            ->json('data.*.id');
    }

    // ---------------------------------------------------------------- the window

    public function test_a_bd_still_sees_work_completed_within_the_last_7_days(): void
    {
        $bd = $this->user('crm_bd');
        $recent = $this->event($bd, $this->lead($bd), 'completed', now()->subDays(6));

        $this->assertContains((string) $recent->id, $this->plannerIds($bd));
    }

    public function test_a_bd_no_longer_sees_work_completed_more_than_7_days_ago(): void
    {
        $bd = $this->user('crm_bd');
        $old = $this->event($bd, $this->lead($bd), 'completed', now()->subDays(8));

        $this->assertNotContains((string) $old->id, $this->plannerIds($bd));
        // Retired from the view, NOT deleted — history and reporting keep every row.
        $this->assertDatabaseHas('crm_planner_events', ['id' => $old->id, 'deleted_at' => null]);
    }

    public function test_the_boundary_is_a_rolling_seven_days(): void
    {
        $bd = $this->user('crm_bd');
        $inside = $this->event($bd, $this->lead($bd), 'completed', now()->subDays(7)->addMinutes(5));
        $outside = $this->event($bd, $this->lead($bd), 'completed', now()->subDays(7)->subMinutes(5));

        $ids = $this->plannerIds($bd);
        $this->assertContains((string) $inside->id, $ids);
        $this->assertNotContains((string) $outside->id, $ids);
    }

    public function test_open_work_is_never_affected_however_old(): void
    {
        $bd = $this->user('crm_bd');
        $lead = $this->lead($bd);
        $ids = [];
        foreach (['todo', 'scheduled', 'in_progress'] as $status) {
            $ids[] = (string) $this->event($bd, $lead, $status)->id;
        }

        $visible = $this->plannerIds($bd);
        foreach ($ids as $id) {
            $this->assertContains($id, $visible);
        }
    }

    public function test_legacy_completed_events_with_no_timestamp_are_hidden_from_a_bd(): void
    {
        $bd = $this->user('crm_bd');
        // Completed before `completed_at` existed — old by definition.
        $legacy = $this->event($bd, $this->lead($bd), 'completed', null);
        $this->assertNull($legacy->completed_at);

        $this->assertNotContains((string) $legacy->id, $this->plannerIds($bd));
    }

    // ---------------------------------------------------------------- consultation exemption

    public function test_a_consultation_awaiting_its_outcome_never_expires(): void
    {
        $bd = $this->user('crm_bd');
        // The Board card is the only BD-reachable place to record an outcome, and the KPI only counts
        // a consultation once that outcome exists — so this row must stay reachable forever.
        $awaiting = $this->event($bd, $this->lead($bd), 'completed', now()->subMonths(6), 'consultation', null);

        $this->assertContains((string) $awaiting->id, $this->plannerIds($bd));
    }

    public function test_a_consultation_with_a_recorded_outcome_expires_normally(): void
    {
        $bd = $this->user('crm_bd');
        $resolved = $this->event(
            $bd, $this->lead($bd), 'completed', now()->subDays(8), 'consultation', 'attended',
        );

        $this->assertNotContains((string) $resolved->id, $this->plannerIds($bd));
    }

    // ---------------------------------------------------------------- management roles exempt

    public function test_admin_still_sees_old_completed_work(): void
    {
        $admin = $this->user('crm_admin');
        $bd = $this->user('crm_bd');
        $old = $this->event($bd, $this->lead($bd), 'completed', now()->subMonths(3));
        $legacy = $this->event($bd, $this->lead($bd), 'completed', null);

        $ids = $this->plannerIds($admin);
        $this->assertContains((string) $old->id, $ids);
        $this->assertContains((string) $legacy->id, $ids);
    }

    public function test_super_admin_still_sees_old_completed_work(): void
    {
        $superAdmin = $this->user('super_admin');
        $bd = $this->user('crm_bd');
        $old = $this->event($bd, $this->lead($bd), 'completed', now()->subMonths(3));

        $this->assertContains((string) $old->id, $this->plannerIds($superAdmin));
    }

    // ---------------------------------------------------------------- reopen

    public function test_reopening_an_expired_event_brings_it_straight_back(): void
    {
        $bd = $this->user('crm_bd');
        $old = $this->event($bd, $this->lead($bd), 'completed', now()->subMonths(2));
        $this->assertNotContains((string) $old->id, $this->plannerIds($bd));

        // Reopen through the real endpoint; the observer nulls completed_at on the way out of
        // `completed`, so the row satisfies the "open work" clause again.
        Sanctum::actingAs($bd);
        $this->patchJson("/api/v1/crm/planner-events/{$old->id}", ['status' => 'todo'])->assertOk();

        $this->assertNull($old->fresh()->completed_at);
        $this->assertContains((string) $old->id, $this->plannerIds($bd));
    }

    // ---------------------------------------------------------------- surface behaviour

    public function test_the_board_and_calendar_read_the_same_windowed_dataset(): void
    {
        $bd = $this->user('crm_bd');
        $lead = $this->lead($bd);
        $old = $this->event($bd, $lead, 'completed', now()->subDays(30));
        $recent = $this->event($bd, $lead, 'completed', now()->subDay());

        // Board: fetches every status through the same single endpoint.
        $board = $this->plannerIds($bd, ['status' => 'completed']);
        $this->assertNotContains((string) $old->id, $board);
        $this->assertContains((string) $recent->id, $board);

        // Calendar: the same endpoint with a date range. The window must survive alongside ?from/?to
        // — this is what the grouped where() protects against.
        $calendar = $this->plannerIds($bd, [
            'from' => now()->subYear()->toIso8601String(),
            'to' => now()->addYear()->toIso8601String(),
        ]);
        $this->assertNotContains((string) $old->id, $calendar);
    }

    // ---------------------------------------------------------------- KPIs must NOT be windowed

    public function test_kpis_still_count_completions_older_than_the_window(): void
    {
        $bd = $this->user('crm_bd');
        // Completed 10 days ago: outside the Planner window, but inside the month-to-date KPI period.
        $this->event($bd, $this->lead($bd), 'completed', now()->startOfMonth()->addDay());

        Sanctum::actingAs($bd);
        $completed = $this->getJson('/api/v1/crm/dashboard/performance-summary')
            ->assertOk()
            ->json('data.completedFollowUps');

        // The whole reason the filter lives in the controller and not in scopeVisibleTo.
        $this->assertSame(1, $completed);
    }
}
