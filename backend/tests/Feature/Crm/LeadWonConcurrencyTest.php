<?php

namespace Tests\Feature\Crm;

use App\Models\User;
use App\Modules\Crm\Events\DealWon;
use App\Modules\Crm\Models\Activity;
use App\Modules\Crm\Models\Deal;
use App\Modules\Crm\Models\Lead;
use App\Modules\Crm\Models\PlannerEvent;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Testing\TestResponse;
use RuntimeException;
use Tests\TestCase;

/**
 * CEO audit crm-14(a) — Mark as Won is exactly-once.
 *
 * `won()` used to check `deals()->exists()` and then insert as two separate, unlocked statements. A
 * second Won that arrived between the two (double submit, second tab, direct API call) passed the same
 * check and created a second Deal: two `lead.won` Activities, two DealWon webhooks (two Thank You
 * emails), and doubled Revenue. The check and both writes now run in one transaction, against the lead
 * row re-read under `lockForUpdate`.
 *
 * The suite runs on SQLite, which has no row locks, so the race is proven the way
 * {@see LeadQualificationTest} proves its claim: a competing Won is committed at the exact point this
 * request's transaction opens — before its locked re-check — and the request must then see that Deal.
 * On the old code there was no transaction to open, so that window test fails outright.
 */
class LeadWonConcurrencyTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->admin = User::factory()->create(['status' => 'active']);
        $this->admin->assignRole('crm_admin');
    }

    /** `cold_calling` is pinned off `scheduled` so LeadObserver creates no unrelated follow-up. */
    private function lead(array $overrides = []): Lead
    {
        return Lead::factory()->create(array_merge([
            'assigned_to' => $this->admin->id,
            'created_by' => $this->admin->id,
            'cold_calling' => 'not-called',
            'status' => 'new',
            'amount' => 120000,
        ], $overrides));
    }

    private function markWon(Lead $lead, array $payload = []): TestResponse
    {
        return $this->actingAs($this->admin)->postJson("/api/v1/crm/leads/{$lead->id}/won", $payload);
    }

    private function wonActivities(Lead $lead): int
    {
        return Activity::query()->where('subject_id', $lead->id)->where('event', 'lead.won')->count();
    }

    // ── 1. Normal Won is unchanged ─────────────────────────────────────────────────────────────

    public function test_a_normal_won_creates_one_deal_one_activity_and_one_won_event(): void
    {
        Event::fake([DealWon::class]);
        $lead = $this->lead();

        $this->markWon($lead)->assertCreated()->assertJsonPath('data.id', (string) $lead->id);

        $deal = Deal::query()->where('lead_id', $lead->id)->sole();
        $this->assertSame('120000.00', $deal->amount, 'an omitted amount still falls back to the lead estimate');
        $this->assertNotNull($deal->converted_at);

        $activity = Activity::query()->where('subject_id', $lead->id)->where('event', 'lead.won')->sole();
        $this->assertSame($deal->id, $activity->properties['deal_id']);

        Event::assertDispatchedTimes(DealWon::class, 1);
    }

    public function test_an_explicit_amount_is_still_the_realized_deal_value(): void
    {
        Event::fake([DealWon::class]);
        $lead = $this->lead();

        $this->markWon($lead, ['amount' => 95000])->assertCreated();

        $this->assertSame('95000.00', Deal::query()->where('lead_id', $lead->id)->sole()->amount);
    }

    public function test_a_sequential_second_won_still_returns_the_existing_409(): void
    {
        Event::fake([DealWon::class]);
        $lead = $this->lead();

        $this->markWon($lead)->assertCreated();
        $this->markWon($lead)
            ->assertStatus(409)
            ->assertExactJson(['message' => 'This lead is already marked as won.']);

        $this->assertSame(1, Deal::query()->where('lead_id', $lead->id)->count());
        $this->assertSame(1, $this->wonActivities($lead));
        Event::assertDispatchedTimes(DealWon::class, 1);
    }

    // ── 2. The race ────────────────────────────────────────────────────────────────────────────

    /**
     * A competing Won commits in the window after this request was routed and before it holds the
     * lead lock — exactly where a real concurrent request lands once MySQL makes it wait on the lock.
     * The locked re-check must see the competitor's Deal: one Deal, one Activity, one DealWon.
     */
    public function test_a_won_committed_while_this_request_waits_for_the_lock_is_refused(): void
    {
        Event::fake([DealWon::class]);
        $lead = $this->lead();

        $fired = false;
        $competitorStatus = null;
        Event::listen(TransactionBeginning::class, function () use (&$fired, &$competitorStatus, $lead): void {
            if ($fired) {
                return;
            }
            $fired = true;
            $competitorStatus = $this->markWon($lead)->status();
        });

        $this->markWon($lead)
            ->assertStatus(409)
            ->assertExactJson(['message' => 'This lead is already marked as won.']);

        $this->assertTrue($fired, 'the race window was never exercised: won() opened no transaction');
        $this->assertSame(201, $competitorStatus, 'the competing Won is the one that wins');
        $this->assertSame(1, Deal::query()->where('lead_id', $lead->id)->count(), 'no duplicate Deal');
        $this->assertSame(1, $this->wonActivities($lead), 'no duplicate lead.won Activity');
        Event::assertDispatchedTimes(DealWon::class, 1);
        $this->assertEquals(120000.0, (float) Deal::query()->where('lead_id', $lead->id)->sum('amount'), 'Revenue is not doubled');
    }

    /** The duplicate check and both writes run inside the endpoint's own transaction. */
    public function test_the_duplicate_check_and_both_writes_run_inside_one_transaction(): void
    {
        Event::fake([DealWon::class]);
        $lead = $this->lead();
        $baseline = DB::transactionLevel();

        $levels = ['deal-check' => [], 'deal-insert' => [], 'activity-insert' => []];
        DB::listen(function (QueryExecuted $query) use (&$levels): void {
            $sql = strtolower($query->sql);
            if (str_contains($sql, 'crm_deals') && str_contains($sql, 'exists')) {
                $levels['deal-check'][] = DB::transactionLevel();
            } elseif (str_starts_with($sql, 'insert into "crm_deals"')) {
                $levels['deal-insert'][] = DB::transactionLevel();
            } elseif (str_starts_with($sql, 'insert into "crm_activities"') && in_array('lead.won', $query->bindings, true)) {
                $levels['activity-insert'][] = DB::transactionLevel();
            }
        });

        $this->markWon($lead)->assertCreated();

        foreach ($levels as $step => $seen) {
            $this->assertNotEmpty($seen, "{$step} was not observed");
            $this->assertGreaterThan($baseline, min($seen), "{$step} ran outside the Won transaction");
        }
    }

    public function test_a_failed_won_activity_rolls_the_deal_back(): void
    {
        Event::fake([DealWon::class]);
        $lead = $this->lead();

        $failOnce = true;
        Activity::creating(function (Activity $activity) use (&$failOnce): void {
            if ($failOnce && $activity->event === 'lead.won') {
                $failOnce = false;
                throw new RuntimeException('simulated lead.won insert failure');
            }
        });

        $this->withoutExceptionHandling();
        try {
            $this->markWon($lead);
            $this->fail('the simulated activity failure did not surface');
        } catch (RuntimeException $e) {
            $this->assertSame('simulated lead.won insert failure', $e->getMessage());
        }

        $this->assertSame(0, Deal::query()->where('lead_id', $lead->id)->count(), 'a Deal without its lead.won Activity was left behind');
        Event::assertNotDispatched(DealWon::class);

        // Nothing half-written blocks a retry.
        $this->withExceptionHandling();
        $this->markWon($lead)->assertCreated();
        $this->assertSame(1, Deal::query()->where('lead_id', $lead->id)->count());
        $this->assertSame(1, $this->wonActivities($lead));
    }

    // ── 3. What happens after the commit keeps its order ───────────────────────────────────────

    /**
     * Planner closure and DealWon stay OUTSIDE the transaction, after the Deal is committed: the
     * escalation check reads "is won" from the Deal, and the webhook must never announce a Deal that
     * could still roll back. `meeting` still survives a Won.
     */
    public function test_planner_closure_and_the_won_event_run_after_the_deal_is_committed(): void
    {
        $lead = $this->lead();
        $call = PlannerEvent::create([
            'title' => 'Chase', 'event_type' => 'call', 'status' => 'scheduled',
            'lead_id' => $lead->id, 'assigned_to' => $this->admin->id, 'created_by' => $this->admin->id,
        ]);
        $meeting = PlannerEvent::create([
            'title' => 'Kickoff', 'event_type' => 'meeting', 'status' => 'scheduled',
            'lead_id' => $lead->id, 'assigned_to' => $this->admin->id, 'created_by' => $this->admin->id,
        ]);
        $baseline = DB::transactionLevel();

        $closureLevels = [];
        $dealExistedAtClosure = [];
        DB::listen(function (QueryExecuted $query) use (&$closureLevels, &$dealExistedAtClosure, $lead): void {
            if (str_starts_with(strtolower($query->sql), 'update "crm_planner_events"')) {
                $closureLevels[] = DB::transactionLevel();
                $dealExistedAtClosure[] = $lead->deals()->exists();
            }
        });
        $wonEventLevels = [];
        Event::listen(DealWon::class, function () use (&$wonEventLevels): void {
            $wonEventLevels[] = DB::transactionLevel();
        });

        $this->markWon($lead)->assertCreated();

        $this->assertSame('completed', $call->fresh()->status);
        $this->assertSame('scheduled', $meeting->fresh()->status, 'a Won still keeps meetings open');
        $this->assertNotEmpty($closureLevels);
        $this->assertSame($baseline, max($closureLevels), 'the Planner closure ran inside the Won transaction');
        $this->assertNotContains(false, $dealExistedAtClosure, 'the Planner closure ran before the Deal existed');
        $this->assertSame([$baseline], $wonEventLevels, 'DealWon must fire exactly once, after the commit');
    }
}
