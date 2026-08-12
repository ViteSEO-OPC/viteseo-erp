<?php

namespace Tests\Feature\Crm;

use App\Models\User;
use App\Modules\Crm\Enums\CallOutcome;
use App\Modules\Crm\Models\Call;
use App\Modules\Crm\Models\Email;
use App\Modules\Crm\Models\Lead;
use App\Modules\Crm\Services\Kpi\BdKpiService;
use App\Modules\Crm\Services\Performance\PerformanceSummaryService;
use App\Modules\Crm\Services\Scoring\LeadScorer;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The canonical engagement definition — {@see Lead::scopeEngaged}.
 *
 * Zerem supports BOTH kinds of outreach evidence and must keep supporting both:
 *
 *  - DECLARED / OFF-SYSTEM — a BD sets `email_outreach` / `cold_calling` by hand because the contact
 *    happened somewhere Zerem cannot see it (a site visit, a personal mobile, an external mailbox).
 *  - ZEREM-VERIFIED — the platform itself holds proof: an outbound email that actually went out, or a
 *    call the BD concluded with a real disposition.
 *
 * These tests pin the boundary in both directions: what must count, and — just as importantly — what
 * must NOT (a scheduled-but-unsent email, a failed send, an abandoned call session). They also pin
 * that this is metric evidence ONLY: the lead's declared fields and its Lead Score are untouched.
 */
class EngagementEvidenceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        // LeadFactory resolves `created_by` from an existing user (falling back to id 1), so at least
        // one user must exist before any lead is made or the FK fails.
        User::factory()->create(['status' => 'active']);
    }

    /** A lead with nothing declared and no activity — the baseline "not engaged" state. */
    private function untouchedLead(): Lead
    {
        return Lead::factory()->create([
            'email_outreach' => 'none',
            'cold_calling' => 'not-called',
            'qualified_at' => null,
            'source' => null, // active CRM lead (manually created)
        ]);
    }

    private function outboundEmail(Lead $lead, string $status): Email
    {
        return Email::create([
            'lead_id' => $lead->id,
            'direction' => 'outbound',
            'to_email' => 'prospect@example.test',
            'subject' => 'Hello',
            'body' => 'Body',
            'provider' => 'smtp',
            'status' => $status,
        ]);
    }

    private function engaged(Lead $lead): bool
    {
        return Lead::query()->engaged()->whereKey($lead->getKey())->exists();
    }

    // ── Declared / off-system evidence must keep working ────────────────────────────────────────

    public function test_declared_email_outreach_still_makes_a_lead_engaged(): void
    {
        $lead = $this->untouchedLead();
        $this->assertFalse($this->engaged($lead));

        $lead->update(['email_outreach' => 'sent']);

        $this->assertTrue($this->engaged($lead), 'an off-system email declaration must still count');
    }

    public function test_declared_cold_calling_still_makes_a_lead_engaged(): void
    {
        $lead = $this->untouchedLead();

        $lead->update(['cold_calling' => 'called']);

        $this->assertTrue($this->engaged($lead), 'an off-system call declaration must still count');
    }

    // ── Zerem-verified email evidence ───────────────────────────────────────────────────────────

    public function test_a_sent_outbound_email_engages_a_lead_with_no_declared_state(): void
    {
        $lead = $this->untouchedLead();
        $this->outboundEmail($lead, 'sent');

        $this->assertTrue($this->engaged($lead));
        // The declared fields are evidence-READ, never evidence-WRITE.
        $this->assertSame('none', $lead->fresh()->email_outreach);
        $this->assertSame('not-called', $lead->fresh()->cold_calling);
    }

    /** @return list<array{0: string}> */
    public static function nonQualifyingEmailStatuses(): array
    {
        return [['draft'], ['scheduled'], ['queued'], ['sending'], ['failed'], ['cancelled']];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('nonQualifyingEmailStatuses')]
    public function test_only_a_sent_email_qualifies(string $status): void
    {
        $lead = $this->untouchedLead();
        $this->outboundEmail($lead, $status);

        $this->assertFalse(
            $this->engaged($lead),
            "an email with status={$status} has not gone out and must not count as outreach",
        );
    }

    /**
     * Send Later: the SAME row starts as `scheduled` and becomes `sent` on delivery. Engagement must
     * follow that transition — not the intent to send.
     */
    public function test_a_scheduled_email_qualifies_only_once_it_is_delivered(): void
    {
        $lead = $this->untouchedLead();
        $email = $this->outboundEmail($lead, 'scheduled');
        $this->assertFalse($this->engaged($lead), 'pending schedule is not outreach');

        $email->update(['status' => 'sent', 'sent_at' => now()]);

        $this->assertTrue($this->engaged($lead), 'delivered scheduled send must equal an immediate send');
    }

    public function test_an_inbound_email_alone_does_not_qualify_as_outbound_evidence(): void
    {
        $lead = $this->untouchedLead();
        Email::create([
            'lead_id' => $lead->id,
            'direction' => 'inbound',
            'from_email' => 'prospect@example.test',
            'to_email' => 'business@example.test',
            'subject' => 'Re: Hello',
            'body' => 'Body',
            'provider' => 'gmail',
            'status' => 'received',
        ]);

        // Inbound replies reach engagement through InboundReplyProcessor setting email_outreach
        // ='replied' (declared branch) — NOT through the outbound-evidence branch.
        $this->assertFalse($this->engaged($lead));
    }

    // ── Zerem-verified call evidence ────────────────────────────────────────────────────────────

    /** @return list<array{0: CallOutcome}> */
    public static function qualifyingCallOutcomes(): array
    {
        // Dispositions that PROVE a dial happened. `connected` reached the prospect; the other three
        // did not, but the call was demonstrably placed — which is the effort M-04 measures.
        return [[CallOutcome::CONNECTED], [CallOutcome::NO_ANSWER], [CallOutcome::BUSY], [CallOutcome::VOICEMAIL]];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('qualifyingCallOutcomes')]
    public function test_a_concluded_call_attempt_engages_the_lead(CallOutcome $outcome): void
    {
        $lead = $this->untouchedLead();
        Call::factory()->forLead($lead)->completed($outcome)->create();

        $this->assertTrue($this->engaged($lead), "{$outcome->value} proves a real attempted call");
        $this->assertSame('not-called', $lead->fresh()->cold_calling, 'cold_calling must not be mutated');
    }

    /**
     * `failed` is ambiguous in the current lifecycle — it may mean the system could not PLACE the call
     * rather than that a dial occurred, and `crm_calls` cannot tell those apart. Verified evidence must
     * prove an attempt, so it is excluded. The enum case itself is untouched, and a BD who really did
     * attempt the call can still declare `cold_calling` by hand.
     */
    public function test_a_failed_call_does_not_qualify_as_verified_evidence(): void
    {
        $lead = $this->untouchedLead();
        Call::factory()->forLead($lead)->completed(CallOutcome::FAILED)->create();

        $this->assertFalse($this->engaged($lead), 'failed does not prove a dial occurred');

        // …but the manual declaration path still works for exactly this case.
        $lead->update(['cold_calling' => 'called']);
        $this->assertTrue($this->engaged($lead));
    }

    public function test_an_in_progress_call_does_not_qualify(): void
    {
        $lead = $this->untouchedLead();
        Call::factory()->forLead($lead)->create(); // factory default = in_progress, outcome null

        $this->assertFalse($this->engaged($lead), 'an open session is not yet a concluded attempt');
    }

    public function test_a_discarded_call_does_not_qualify(): void
    {
        $lead = $this->untouchedLead();
        Call::factory()->forLead($lead)->completed(CallOutcome::CANCELLED)->create();

        $this->assertFalse(
            $this->engaged($lead),
            'Discard abandons a session without a real disposition — it is not an attempt',
        );
    }

    // ── Unique leads, not actions ───────────────────────────────────────────────────────────────

    public function test_many_activities_on_one_lead_count_that_lead_once(): void
    {
        $lead = $this->untouchedLead();
        $this->outboundEmail($lead, 'sent');
        $this->outboundEmail($lead, 'sent');
        $this->outboundEmail($lead, 'sent');
        Call::factory()->forLead($lead)->completed()->create();
        Call::factory()->forLead($lead)->completed(CallOutcome::NO_ANSWER)->create();

        $this->assertSame(1, Lead::query()->engaged()->whereKey($lead->getKey())->count());
    }

    // ── One canonical definition across consumers ───────────────────────────────────────────────

    public function test_m04_and_reports_contacted_use_the_same_engagement_population(): void
    {
        $admin = User::factory()->create(['status' => 'active']);
        $admin->assignRole('crm_admin');

        $declared = $this->untouchedLead();
        $declared->update(['cold_calling' => 'scheduled']);
        $verifiedEmail = $this->untouchedLead();
        $this->outboundEmail($verifiedEmail, 'sent');
        $verifiedCall = $this->untouchedLead();
        Call::factory()->forLead($verifiedCall)->completed(CallOutcome::NO_ANSWER)->create();
        $this->untouchedLead(); // not engaged at all

        $canonical = Lead::query()->active()->visibleTo($admin)->engaged()->count();

        // M-04's numerator (KPI Engine) and the performance summary's engagedLeads must agree with it.
        $summary = app(PerformanceSummaryService::class)->for($admin);
        $this->assertSame($canonical, $summary['engagedLeads']);

        $assigned = Lead::query()->active()->visibleTo($admin)->count();
        $m04 = app(BdKpiService::class)->forUser($admin)->metricValue('M-04');
        $this->assertSame(round($canonical / $assigned * 100, 1), $m04, 'M-04 must be built on the same population');

        $this->assertSame(3, $canonical, 'declared + verified-email + verified-call, minus the untouched one');
    }

    // ── Lead Scoring must be untouched by evidence ──────────────────────────────────────────────

    public function test_verified_activity_does_not_change_the_lead_score(): void
    {
        $lead = $this->untouchedLead();
        $scorer = app(LeadScorer::class);
        $before = $scorer->calculate($lead->fresh())->score;

        $this->outboundEmail($lead, 'sent');
        Call::factory()->forLead($lead)->completed(CallOutcome::CONNECTED)->create();

        $after = $scorer->calculate($lead->fresh())->score;

        $this->assertSame($before, $after, 'engagement evidence is reporting-only — scoring reads the declared fields');
        $this->assertTrue($this->engaged($lead), 'yet the lead IS engaged for reporting');
    }

    public function test_declared_fields_still_drive_the_lead_score_exactly_as_before(): void
    {
        $lead = $this->untouchedLead();
        $scorer = app(LeadScorer::class);
        $before = $scorer->calculate($lead->fresh())->score;

        $lead->update(['cold_calling' => 'called']); // an existing scoring input

        $this->assertGreaterThan(
            $before,
            $scorer->calculate($lead->fresh())->score,
            'manual cold_calling must keep awarding its existing points',
        );
    }

    // ── The funnel's Interested stage stays nested in the engaged cohort ─────────────────────────

    public function test_interested_stage_remains_constrained_by_the_engaged_cohort(): void
    {
        $admin = User::factory()->create(['status' => 'active']);
        $admin->assignRole('crm_admin');

        // Interested status but NO outreach of any kind → must not reach the Interested stage.
        $lonely = $this->untouchedLead();
        $lonely->update(['status' => 'interested']);

        $interested = Lead::query()->active()->visibleTo($admin)->engaged()
            ->whereIn('status', ['interested', 'awaiting-reply', 'call-back-req'])->count();

        $this->assertSame(0, $interested);

        // Give it verified evidence — now it qualifies for both stages.
        $this->outboundEmail($lonely, 'sent');

        $this->assertSame(1, Lead::query()->active()->visibleTo($admin)->engaged()
            ->whereIn('status', ['interested', 'awaiting-reply', 'call-back-req'])->count());
    }
}
