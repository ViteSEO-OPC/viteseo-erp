<?php

namespace Tests\Feature\Crm;

use App\Models\User;
use App\Modules\Crm\Events\FollowUpCompleted;
use App\Modules\Crm\Models\Lead;
use App\Modules\Crm\Models\PlannerEvent;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/**
 * `crm:backfill-planner-ownership` — the one-shot reconciliation of Planner ownership drift that
 * predates the forward transfer.
 *
 * The property these tests exist to protect is that the DEFAULT run cannot write. Everything else
 * (idempotency, completed-history protection, the exclusion rules) is checked against `--apply`,
 * which is the only path allowed to mutate anything.
 */
class BackfillPlannerOwnershipCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    /** Names are explicit: UserFactory produces long titled names that the report's table truncates. */
    private function bd(?string $name = null): User
    {
        $user = User::factory()->create(array_filter([
            'status' => 'active',
            'name' => $name,
        ]));
        $user->assignRole('crm_bd');

        return $user;
    }

    /**
     * `cold_calling` pinned — LeadFactory randomises it, and 'scheduled' auto-mints an extra event.
     *
     * `business_name` is pinned too, and short on purpose: the report truncates the company column to
     * 30 characters (mb_strimwidth), so a randomly-generated longer name makes any assertion against
     * the full name fail intermittently.
     */
    private function lead(?User $owner, string $name = 'Acme Co'): Lead
    {
        return Lead::factory()->create([
            'assigned_to' => $owner?->id,
            'business_name' => $name,
            'cold_calling' => 'not-called',
        ]);
    }

    /** Creates drift directly: an event owned by someone other than the lead's owner. */
    private function event(?User $owner, ?Lead $lead, string $status = 'todo', string $type = 'follow-up'): PlannerEvent
    {
        return PlannerEvent::create([
            'title' => 'Drifted work',
            'event_type' => $type,
            'status' => $status,
            'lead_id' => $lead?->id,
            'assigned_to' => $owner?->id,
            'created_by' => $owner?->id,
        ]);
    }

    // ---------------------------------------------------------------- the read-only guarantee

    public function test_the_default_run_writes_nothing(): void
    {
        $stale = $this->bd();
        $current = $this->bd();
        $lead = $this->lead($current);
        $event = $this->event($stale, $lead);

        $activitiesBefore = DB::table('crm_activities')->count();

        // Capture every statement the command issues. This is the assertion that matters: not "the
        // data looks the same afterwards", but "no write was ever sent to the database".
        $writes = [];
        DB::listen(function ($query) use (&$writes): void {
            if (preg_match('/^\s*(insert|update|delete)\s/i', $query->sql)) {
                $writes[] = $query->sql;
            }
        });

        $this->artisan('crm:backfill-planner-ownership')->assertSuccessful();

        $this->assertSame([], $writes, 'the default run must issue no INSERT/UPDATE/DELETE');
        $this->assertSame($stale->id, $event->fresh()->assigned_to, 'ownership must be untouched');
        $this->assertSame($activitiesBefore, DB::table('crm_activities')->count());
    }

    public function test_the_report_lists_the_drifted_event_and_its_proposed_transfer(): void
    {
        $stale = $this->bd('Stale Owner');
        $current = $this->bd('Current Owner');
        $lead = $this->lead($current);
        $event = $this->event($stale, $lead);

        // Artisan::call rather than $this->artisan(): PendingCommand's expectation matcher does not
        // capture Symfony table output, and the table IS the report. This asserts the real buffer.
        $this->assertSame(0, Artisan::call('crm:backfill-planner-ownership'));
        $output = Artisan::output();

        $this->assertStringContainsString('READ-ONLY REPORT', $output);
        $this->assertStringContainsString((string) $event->id, $output);
        $this->assertStringContainsString($lead->business_name, $output);
        // The proposed transfer, as the reviewer will read it: old BD → new BD.
        $this->assertStringContainsString(sprintf('Stale Owner #%d', $stale->id), $output);
        $this->assertStringContainsString(sprintf('Current Owner #%d', $current->id), $output);
        $this->assertStringContainsString('Nothing was written.', $output);
    }

    public function test_a_clean_database_reports_no_drift(): void
    {
        $bd = $this->bd();
        $lead = $this->lead($bd);
        $this->event($bd, $lead); // aligned already

        $this->artisan('crm:backfill-planner-ownership')
            ->expectsOutputToContain('No open Planner ownership drift found')
            ->assertSuccessful();
    }

    // ---------------------------------------------------------------- exclusion rules

    public function test_completed_drift_is_never_transferred_even_with_apply(): void
    {
        $stale = $this->bd();
        $current = $this->bd();
        $lead = $this->lead($current);
        $done = $this->event($stale, $lead, 'completed');
        $completedAt = $done->fresh()->completed_at;

        $this->artisan('crm:backfill-planner-ownership --apply')->assertSuccessful();

        $done->refresh();
        $this->assertSame($stale->id, $done->assigned_to, 'completed history must keep its performer');
        // Proves PlannerEventObserver::saving() did not restamp anything — the month window that
        // "Completed Follow-ups" reads would shift if it had.
        $this->assertEquals($completedAt, $done->completed_at);
    }

    public function test_lead_less_events_are_ignored(): void
    {
        $bd = $this->bd();
        $orphan = $this->event($bd, null);

        $this->artisan('crm:backfill-planner-ownership --apply')->assertSuccessful();

        $this->assertSame($bd->id, $orphan->fresh()->assigned_to);
    }

    public function test_events_on_unassigned_leads_are_never_moved_to_nobody(): void
    {
        $holder = $this->bd();
        $lead = $this->lead(null);
        $event = $this->event($holder, $lead);

        $this->artisan('crm:backfill-planner-ownership --apply')->assertSuccessful();

        $this->assertSame($holder->id, $event->fresh()->assigned_to, 'work must never be orphaned');
    }

    public function test_ownerless_events_are_not_treated_as_drift(): void
    {
        $current = $this->bd();
        $lead = $this->lead($current);
        // Unclaimed work is a different defect from drift between two BDs; this command must not
        // silently assign it.
        $unowned = $this->event(null, $lead);

        $this->artisan('crm:backfill-planner-ownership --apply')->assertSuccessful();

        $this->assertNull($unowned->fresh()->assigned_to);
    }

    public function test_soft_deleted_events_are_ignored(): void
    {
        $stale = $this->bd();
        $current = $this->bd();
        $lead = $this->lead($current);
        $archived = $this->event($stale, $lead);
        $archived->delete();

        $this->artisan('crm:backfill-planner-ownership --apply')->assertSuccessful();

        $this->assertSame($stale->id, $archived->fresh()->assigned_to);
    }

    // ---------------------------------------------------------------- the apply path

    public function test_apply_transfers_open_work_and_logs_a_reassignment_activity(): void
    {
        $stale = $this->bd();
        $current = $this->bd();
        $lead = $this->lead($current);
        $event = $this->event($stale, $lead);

        $this->artisan('crm:backfill-planner-ownership --apply')->assertSuccessful();

        $this->assertSame($current->id, $event->fresh()->assigned_to);

        $activity = $lead->activities()
            ->where('event', 'planner-event.reassigned')
            ->get()
            ->sole(fn ($a): bool => $a->properties['planner_event_id'] === $event->id);
        $this->assertSame($stale->id, $activity->properties['from']);
        $this->assertSame($current->id, $activity->properties['to']);
        // A CLI backfill has no authenticated user — the null causer is the honest record of a
        // system action, not a bug to paper over with a fake admin.
        $this->assertNull($activity->causer_id);
    }

    public function test_apply_is_idempotent(): void
    {
        $stale = $this->bd();
        $current = $this->bd();
        $lead = $this->lead($current);
        $this->event($stale, $lead);

        $this->artisan('crm:backfill-planner-ownership --apply')->assertSuccessful();
        $afterFirst = DB::table('crm_activities')->count();

        $this->artisan('crm:backfill-planner-ownership --apply')
            ->expectsOutputToContain('No open Planner ownership drift found')
            ->assertSuccessful();

        $this->assertSame($afterFirst, DB::table('crm_activities')->count(), 'a second run must write nothing');
    }

    public function test_the_lead_option_restricts_the_run(): void
    {
        $stale = $this->bd();
        $current = $this->bd();
        $targeted = $this->lead($current);
        $untouched = $this->lead($current);
        $inScope = $this->event($stale, $targeted);
        $outOfScope = $this->event($stale, $untouched);

        $this->artisan("crm:backfill-planner-ownership --apply --lead={$targeted->id}")->assertSuccessful();

        $this->assertSame($current->id, $inScope->fresh()->assigned_to);
        $this->assertSame($stale->id, $outOfScope->fresh()->assigned_to, 'other leads must be left alone');
    }

    public function test_apply_never_dispatches_follow_up_completed(): void
    {
        $stale = $this->bd();
        $current = $this->bd();
        $lead = $this->lead($current);
        $this->event($stale, $lead);

        Event::fake([FollowUpCompleted::class]);
        $this->artisan('crm:backfill-planner-ownership --apply')->assertSuccessful();

        // A bulk ownership run must not look like completed work: FollowUpCompleted drives the
        // no-response escalation and the outreach webhook.
        Event::assertNotDispatched(FollowUpCompleted::class);
    }
}
