<?php

namespace Tests\Feature\Crm;

use App\Models\User;
use App\Modules\Crm\Models\Deal;
use App\Modules\Crm\Models\Lead;
use App\Modules\Crm\Models\PlannerEvent;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Defer\DeferredCallbackCollection;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The Management Dashboard's "BD Performance Overview" row (`GET /dashboard/bd-performance`).
 *
 * The card reports four live figures per BD, and the three derived ones carry approved definitions
 * that this endpoint must NOT restate in its own terms:
 *
 *  - **Follow-ups** is `PerformanceSummaryService`'s `completedFollowUps`: work in the approved
 *    category {@see PlannerEvent::FOLLOW_UP_WORK_TYPES} that is `completed` and stamped
 *    `completed_at` within the current month. The category boundary is the fragile part — `task`
 *    and `consultation` sit outside it deliberately — so it is pinned from both directions.
 *  - **Converted** is the existence of a Deal (Business-Computation-Mapping §3.3). It is never
 *    read from `leads.status` — no status in the enum means "won", so a status-derived count would
 *    be an invented business rule. One test pins exactly that.
 *  - **Rate** is converted ÷ ENGAGED (§3.5), not converted ÷ assigned. The denominator is the
 *    canonical {@see Lead::scopeEngaged()} predicate shared with M-04, so "of the leads we actually
 *    worked, how many converted" — which means an unworked portfolio shrinks the denominator, not
 *    the numerator.
 *
 * THE TWO ZEROES ARE NOT THE SAME ZERO, and this is where a plausible-looking bug would hide.
 * `followUps` is a COUNT, so 0 means "completed nothing this month" — a real measurement that must
 * render as 0. `rate` is a RATIO, so a BD who has engaged nobody must report `null`, rendered as an
 * em dash; a fabricated 0% would claim they worked leads and converted none. Tests below assert
 * each zero is of its own kind and has not borrowed the other's representation.
 */
class DashboardBdPerformanceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    private function user(string $role, string $name = 'Test Person'): User
    {
        $user = User::factory()->create(['status' => 'active', 'name' => $name]);
        $user->assignRole($role);

        return $user;
    }

    /** A lead that counts as ENGAGED (outreach was sent). `source` is null, so it is also active. */
    private function engagedLead(User $bd): Lead
    {
        return Lead::factory()->create([
            'assigned_to' => $bd->id,
            'email_outreach' => 'sent',
            'cold_calling' => 'not-called',
        ]);
    }

    /** A lead that is active but NOT engaged — no outreach of any kind. */
    private function untouchedLead(User $bd): Lead
    {
        return Lead::factory()->create([
            'assigned_to' => $bd->id,
            'email_outreach' => 'none',
            'cold_calling' => 'not-called',
        ]);
    }

    /**
     * A Planner event owed by $bd, optionally forced to an exact completion time.
     *
     * The second write is deliberate and mirrors {@see PlannerCompletedWindowTest}: `completed_at`
     * is absent from PlannerEvent's #[Fillable] list because PlannerEventObserver is its only
     * writer, so a mass-assignment attempt is silently discarded. Setting the attribute directly
     * and then `saveQuietly()` keeps the observer from re-stamping it to `now()` — which is the
     * only way to produce a last-month completion, or the null of a legacy row.
     */
    private function plannerEvent(
        User $bd,
        string $type,
        string $status = 'completed',
        ?Carbon $completedAt = null,
    ): PlannerEvent {
        $event = PlannerEvent::create([
            'title' => ucfirst($type).' work',
            'event_type' => $type,
            'status' => $status,
            'assigned_to' => $bd->id,
            'created_by' => $bd->id,
        ]);

        if ($status === 'completed') {
            $event->completed_at = $completedAt ?? now();
            $event->saveQuietly();
        }

        return $event->fresh();
    }

    private function rowFor(User $admin, User $bd): array
    {
        Sanctum::actingAs($admin);

        $response = $this->getJson('/api/v1/crm/dashboard/bd-performance')->assertOk();

        $row = collect($response->json('data'))->firstWhere('id', (string) $bd->id);
        $this->assertNotNull($row, "no row returned for BD {$bd->name}");

        return $row;
    }

    public function test_assigned_still_counts_every_active_lead_regardless_of_engagement(): void
    {
        $admin = $this->user('crm_admin', 'Ada Admin');
        $bd = $this->user('crm_bd', 'Bea Dev');

        $this->engagedLead($bd);
        $this->untouchedLead($bd);
        $this->untouchedLead($bd);

        // Another BD's lead must not leak into this row.
        $this->engagedLead($this->user('crm_bd', 'Other Dev'));

        $row = $this->rowFor($admin, $bd);

        $this->assertSame(3, $row['assigned'], 'Assigned must remain a raw count of the BD\'s active leads');
    }

    public function test_converted_counts_leads_with_at_least_one_deal(): void
    {
        $admin = $this->user('crm_admin', 'Ada Admin');
        $bd = $this->user('crm_bd', 'Bea Dev');

        $won = $this->engagedLead($bd);
        Deal::factory()->create(['lead_id' => $won->id]);

        // Two deals on ONE lead is still ONE converted lead — the metric counts leads, not deals.
        Deal::factory()->create(['lead_id' => $won->id]);

        $this->engagedLead($bd);

        $row = $this->rowFor($admin, $bd);

        $this->assertSame(1, $row['converted'], 'Converted counts LEADS holding ≥1 deal, not deals');
    }

    public function test_a_lead_without_a_deal_never_counts_as_converted_whatever_its_status(): void
    {
        $admin = $this->user('crm_admin', 'Ada Admin');
        $bd = $this->user('crm_bd', 'Bea Dev');

        // The furthest-along status in the enum, deliberately with no Deal attached. Conversion is
        // the Deal, so this must not count — this is the guard against a status-derived proxy.
        Lead::factory()->create([
            'assigned_to' => $bd->id,
            'status' => 'interested',
            'email_outreach' => 'replied',
            'cold_calling' => 'called',
        ]);

        $row = $this->rowFor($admin, $bd);

        $this->assertSame(0, $row['converted'], 'status must never stand in for a Deal');
    }

    public function test_rate_is_converted_over_engaged_to_one_decimal(): void
    {
        $admin = $this->user('crm_admin', 'Ada Admin');
        $bd = $this->user('crm_bd', 'Bea Dev');

        // 3 engaged, 1 of them converted → 33.3%. The 2 untouched leads inflate `assigned` to 5 but
        // must NOT enter the denominator; assigned-based maths would give 20.0%.
        foreach (range(1, 3) as $i) {
            $lead = $this->engagedLead($bd);
            if ($i === 1) {
                Deal::factory()->create(['lead_id' => $lead->id]);
            }
        }
        $this->untouchedLead($bd);
        $this->untouchedLead($bd);

        $row = $this->rowFor($admin, $bd);

        $this->assertSame(5, $row['assigned']);
        $this->assertSame(1, $row['converted']);
        $this->assertSame(33.3, $row['rate'], 'rate denominator must be engaged leads, not assigned');
    }

    public function test_rate_is_null_and_never_zero_when_the_bd_has_engaged_nobody(): void
    {
        $admin = $this->user('crm_admin', 'Ada Admin');
        $bd = $this->user('crm_bd', 'Bea Dev');

        $this->untouchedLead($bd);
        $this->untouchedLead($bd);

        $row = $this->rowFor($admin, $bd);

        $this->assertSame(2, $row['assigned']);
        $this->assertSame(0, $row['converted']);
        $this->assertNull($row['rate'], 'an undefined rate must be null — 0% would be a false claim');
        $this->assertNotSame(0, $row['rate']);
        $this->assertNotSame(0.0, $row['rate']);
    }

    public function test_a_bd_with_no_leads_at_all_reports_zeroes_and_a_null_rate(): void
    {
        $admin = $this->user('crm_admin', 'Ada Admin');
        $bd = $this->user('crm_bd', 'Bea Dev');

        $row = $this->rowFor($admin, $bd);

        $this->assertSame(0, $row['assigned']);
        $this->assertSame(0, $row['converted']);
        $this->assertNull($row['rate']);
    }

    public function test_follow_ups_counts_follow_up_work_completed_this_month(): void
    {
        $admin = $this->user('crm_admin', 'Ada Admin');
        $bd = $this->user('crm_bd', 'Bea Dev');

        $this->plannerEvent($bd, 'follow-up');
        $this->plannerEvent($bd, 'follow-up');

        // Another BD's completed work must not leak into this row.
        $this->plannerEvent($this->user('crm_bd', 'Other Dev'), 'follow-up');

        $row = $this->rowFor($admin, $bd);

        $this->assertSame(2, $row['followUps']);
    }

    public function test_follow_up_work_that_is_not_completed_is_never_counted(): void
    {
        $admin = $this->user('crm_admin', 'Ada Admin');
        $bd = $this->user('crm_bd', 'Bea Dev');

        // Scheduling work is not doing it — every non-completed lifecycle state must be ignored.
        foreach (['todo', 'scheduled', 'in_progress'] as $status) {
            $this->plannerEvent($bd, 'follow-up', $status);
        }

        $row = $this->rowFor($admin, $bd);

        $this->assertSame(0, $row['followUps'], 'planned work must never count as performed work');
    }

    public function test_every_approved_follow_up_work_type_is_counted(): void
    {
        $admin = $this->user('crm_admin', 'Ada Admin');
        $bd = $this->user('crm_bd', 'Bea Dev');

        // The category is a CHANNEL set: `follow-up` is the legacy/generic member, the other three
        // are the channels through which the same work gets done. All four count equally.
        foreach (PlannerEvent::FOLLOW_UP_WORK_TYPES as $type) {
            $this->plannerEvent($bd, $type);
        }

        $row = $this->rowFor($admin, $bd);

        $this->assertSame(
            count(PlannerEvent::FOLLOW_UP_WORK_TYPES),
            $row['followUps'],
            'the count must read the approved category, not a narrower hand-written type list',
        );
    }

    public function test_task_and_consultation_are_excluded_from_follow_ups(): void
    {
        $admin = $this->user('crm_admin', 'Ada Admin');
        $bd = $this->user('crm_bd', 'Bea Dev');

        // `task` is the OUTPUT of completed follow-up work (EscalateUnansweredFollowUp creates it),
        // so counting it would score one BD action twice.
        $this->plannerEvent($bd, 'task');
        // `consultation` is a business milestone with its own KPI and its own outcome axis (PDR-002).
        $this->plannerEvent($bd, 'consultation');

        $row = $this->rowFor($admin, $bd);

        $this->assertSame(0, $row['followUps']);
        $this->assertNotContains('task', PlannerEvent::FOLLOW_UP_WORK_TYPES);
        $this->assertNotContains('consultation', PlannerEvent::FOLLOW_UP_WORK_TYPES);
    }

    public function test_follow_ups_respects_the_current_month_boundary(): void
    {
        $admin = $this->user('crm_admin', 'Ada Admin');
        $bd = $this->user('crm_bd', 'Bea Dev');

        $startOfMonth = now()->startOfMonth();

        // Exactly on the boundary counts — the comparison is `>=`.
        $this->plannerEvent($bd, 'follow-up', 'completed', $startOfMonth->copy());
        // One second earlier belongs to last month.
        $this->plannerEvent($bd, 'follow-up', 'completed', $startOfMonth->copy()->subSecond());
        $this->plannerEvent($bd, 'email', 'completed', $startOfMonth->copy()->subMonth());
        // A legacy row completed before `completed_at` existed carries null and is excluded — the
        // same honest omission PerformanceSummaryService makes rather than guessing from updated_at.
        $legacy = $this->plannerEvent($bd, 'call', 'completed', null);
        $legacy->completed_at = null;
        $legacy->saveQuietly();

        $row = $this->rowFor($admin, $bd);

        $this->assertSame(1, $row['followUps'], 'only completions inside the current month count');
    }

    public function test_a_bd_who_completed_nothing_reports_zero_not_an_undefined_value(): void
    {
        $admin = $this->user('crm_admin', 'Ada Admin');
        $bd = $this->user('crm_bd', 'Bea Dev');

        $this->untouchedLead($bd);

        $row = $this->rowFor($admin, $bd);

        // A count has no zero-denominator, so 0 is a real measurement and must survive as an
        // integer. `rate` in the same row is null — the two zeroes must not converge.
        $this->assertSame(0, $row['followUps']);
        $this->assertNotNull($row['followUps'], 'a count must never be null — that is rate\'s rule');
        $this->assertNull($row['rate']);
    }

    public function test_the_query_count_does_not_grow_with_the_number_of_bds(): void
    {
        $admin = $this->user('crm_admin', 'Ada Admin');

        $first = $this->user('crm_bd', 'Solo Dev');
        Deal::factory()->create(['lead_id' => $this->engagedLead($first)->id]);
        $this->plannerEvent($first, 'follow-up');

        Sanctum::actingAs($admin);

        // Warm-up: the FIRST authenticated request of a test also resolves the acting user's roles
        // and permissions, which are then cached. Measuring it would compare a cold request against
        // a warm one and read the difference as a query-count change that has nothing to do with
        // the aggregates under test.
        $this->getJson('/api/v1/crm/dashboard/bd-performance')->assertOk();

        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->getJson('/api/v1/crm/dashboard/bd-performance')->assertOk();
        $withOneBd = count(DB::getQueryLog());
        DB::disableQueryLog();

        // Six more BDs, each with their own leads, deals and completed follow-up work.
        foreach (range(1, 6) as $i) {
            $bd = $this->user('crm_bd', "Extra Dev {$i}");
            Deal::factory()->create(['lead_id' => $this->engagedLead($bd)->id]);
            $this->untouchedLead($bd);
            $this->plannerEvent($bd, 'follow-up');
            $this->plannerEvent($bd, 'email');
        }

        // Granting each new BD their role queued an access check for them (AccessLifecycle). In
        // production that runs after the response of the request that granted it; a test has no
        // such request, so it would drain inside the next one -- this measurement -- and be read
        // as queries the dashboard made. Run it here, where it belongs.
        app(DeferredCallbackCollection::class)->invoke();

        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->getJson('/api/v1/crm/dashboard/bd-performance')->assertOk();
        $withSevenBds = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertSame(
            $withOneBd,
            $withSevenBds,
            "N+1 regression: {$withOneBd} queries for 1 BD but {$withSevenBds} for 7 — the per-BD "
            .'figures must stay GROUP BY aggregates, never a per-row service call',
        );
    }
}
