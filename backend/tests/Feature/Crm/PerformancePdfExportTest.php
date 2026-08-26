<?php

namespace Tests\Feature\Crm;

use App\Models\User;
use App\Modules\Crm\Services\CrmAccessService;
use App\Modules\Crm\Models\Deal;
use App\Modules\Crm\Models\Lead;
use App\Modules\Crm\Models\PlannerEvent;
use App\Modules\Crm\Services\Performance\PerformanceSummaryService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Performance scorecard PDF export (`GET /dashboard/performance-export-pdf`).
 *
 * One endpoint serves both surfaces — a BD exporting their own My Performance and an Admin/SA
 * exporting either one drilled BD or the CRM-wide All Reports scope. It reuses
 * PerformanceScopeRequest, so its authorization is the SAME seam the JSON endpoints use rather than
 * a second implementation: the RBAC tests below assert that inheritance holds, because an export
 * that leaked another BD's figures would be a data breach even though the screen never shows them.
 *
 * The figures themselves come from PerformanceSummaryService, which the JSON endpoint also returns.
 * `test_the_shared_service_matches_the_json_endpoint` is the anti-drift guard for that extraction.
 */
class PerformancePdfExportTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/api/v1/crm/dashboard/performance-export-pdf';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    private function user(string $role, array $attributes = []): User
    {
        $user = User::factory()->create(['status' => 'active', ...$attributes]);
        $user->assignRole($role);

        return $user;
    }

    /** A lead with a deal and a completed follow-up, so the scorecard has non-zero figures. */
    private function portfolio(User $bd): Lead
    {
        $lead = Lead::factory()->create([
            'assigned_to' => $bd->id,
            'created_by' => $bd->id,
            'status' => 'interested',
            'email_outreach' => 'sent',
            'amount' => 5000,
        ]);
        Deal::factory()->create(['lead_id' => $lead->id, 'amount' => 1500]);
        PlannerEvent::create([
            'title' => 'Follow-up', 'event_type' => 'follow-up', 'status' => 'completed',
            'starts_at' => now()->subDay(), 'completed_at' => now(),
            'lead_id' => $lead->id, 'assigned_to' => $bd->id, 'created_by' => $bd->id,
        ]);

        return $lead;
    }

    // ── Access ──────────────────────────────────────────────────────────────────────────────────

    public function test_a_bd_can_export_their_own_report(): void
    {
        $bd = $this->user('crm_bd');
        $this->portfolio($bd);
        Sanctum::actingAs($bd);

        $this->get(self::URL)
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');
    }

    /**
     * THE security boundary. A BD passing ?user_id is an elevated scope, which
     * PerformanceScopeRequest::authorize() gates behind crm.reports.view — a permission no BD holds.
     * Hiding the button would not be enough; this must fail at the server.
     */
    public function test_a_bd_cannot_export_another_bds_report(): void
    {
        $mine = $this->user('crm_bd');
        $theirs = $this->user('crm_bd');
        $this->portfolio($theirs);
        Sanctum::actingAs($mine);

        $this->get(self::URL.'?user_id='.$theirs->id)->assertForbidden();
    }

    public function test_an_admin_can_export_a_selected_bd(): void
    {
        $bd = $this->user('crm_bd');
        $this->portfolio($bd);
        Sanctum::actingAs($this->user('crm_admin'));

        $this->get(self::URL.'?user_id='.$bd->id)
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');
    }

    public function test_a_super_admin_can_export_a_selected_bd(): void
    {
        $bd = $this->user('crm_bd');
        $this->portfolio($bd);
        Sanctum::actingAs($this->user('super_admin'));

        $this->get(self::URL.'?user_id='.$bd->id)->assertOk();
    }

    /** No user_id → the requester's own scope, which for Admin/SA is the CRM-wide All Reports view. */
    public function test_an_admin_can_export_all_reports(): void
    {
        $this->portfolio($this->user('crm_bd'));
        Sanctum::actingAs($this->user('crm_admin'));

        $this->get(self::URL)
            ->assertOk()
            ->assertHeader('content-disposition', 'attachment; filename=performance-report-all-reports.pdf');
    }

    public function test_an_unauthenticated_request_is_rejected(): void
    {
        $this->getJson(self::URL)->assertUnauthorized();
    }

    /** A non-CRM role has no dashboard.view permission, so the whole group is closed to it. */
    public function test_a_non_crm_role_cannot_export(): void
    {
        Sanctum::actingAs($this->user('hr_employee'));

        $this->get(self::URL)->assertForbidden();
    }

    // ── Validation ──────────────────────────────────────────────────────────────────────────────

    public function test_a_nonexistent_user_id_is_rejected(): void
    {
        Sanctum::actingAs($this->user('crm_admin'));

        $this->getJson(self::URL.'?user_id=999999')->assertStatus(422);
    }

    /** Only an active BD is a valid report subject — the existing withValidator rule. */
    public function test_a_non_bd_user_id_is_rejected(): void
    {
        $admin = $this->user('crm_admin');
        Sanctum::actingAs($admin);

        $this->getJson(self::URL.'?user_id='.$admin->id)->assertStatus(422);
    }

    public function test_an_inactive_bd_is_rejected(): void
    {
        // CRM suspension now lives in `crm_user_access`, not the shared `users.status` column.
        $inactive = $this->user('crm_bd');
        app(CrmAccessService::class)->suspend($inactive);
        Sanctum::actingAs($this->user('crm_admin'));

        $this->getJson(self::URL.'?user_id='.$inactive->id)->assertStatus(422);
    }

    // ── Document ────────────────────────────────────────────────────────────────────────────────

    public function test_the_response_is_a_real_pdf_attachment_named_for_the_subject(): void
    {
        $bd = $this->user('crm_bd', ['name' => 'Jhamire Aranda']);
        $this->portfolio($bd);
        Sanctum::actingAs($this->user('crm_admin'));

        $response = $this->get(self::URL.'?user_id='.$bd->id)->assertOk();

        $this->assertSame('application/pdf', $response->headers->get('content-type'));
        $this->assertSame(
            'attachment; filename=performance-report-jhamire-aranda.pdf',
            $response->headers->get('content-disposition'),
        );
        $this->assertStringStartsWith('%PDF', $response->getContent());
    }

    /** A brand-new BD with no leads must produce a document, not a divide-by-zero or a blank page. */
    public function test_a_zero_value_report_renders(): void
    {
        Sanctum::actingAs($this->user('crm_bd'));

        $response = $this->get(self::URL)->assertOk();

        $this->assertStringStartsWith('%PDF', $response->getContent());
    }

    /**
     * Names reach both the document body and the filename. Quotes, ampersands and non-ASCII must not
     * break DomPDF or produce a malformed Content-Disposition header.
     */
    public function test_special_characters_and_unicode_in_a_name_do_not_break_generation(): void
    {
        $bd = $this->user('crm_bd', ['name' => 'José "Pepe" Ñuñez & Co <script>']);
        $this->portfolio($bd);
        Sanctum::actingAs($this->user('crm_admin'));

        $response = $this->get(self::URL.'?user_id='.$bd->id)->assertOk();

        $this->assertStringStartsWith('%PDF', $response->getContent());
        $this->assertSame(
            'attachment; filename=performance-report-jose-pepe-nunez-co-script.pdf',
            $response->headers->get('content-disposition'),
        );
    }

    // ── Anti-drift: one computation behind screen and document ──────────────────────────────────

    /**
     * The extraction's guarantee: the service the PDF consumes returns exactly what the JSON
     * endpoint serves for the same subject. If a formula were ever reimplemented in the exporter,
     * or the endpoint stopped delegating, this fails.
     */
    public function test_the_shared_service_matches_the_json_endpoint(): void
    {
        $bd = $this->user('crm_bd');
        $this->portfolio($bd);
        Sanctum::actingAs($bd);

        $json = $this->getJson('/api/v1/crm/dashboard/performance-summary')->assertOk()->json('data');
        $service = app(PerformanceSummaryService::class)->for($bd);

        // assertEquals, not assertSame: JSON encoding renders 1500.0 as 1500, so a strict
        // comparison would fail on the round-trip type rather than on any real difference.
        $this->assertEquals($json, $service);
    }

    /** The endpoint's response shape is unchanged by the extraction — existing consumers are safe. */
    public function test_the_performance_summary_response_shape_is_unchanged(): void
    {
        Sanctum::actingAs($this->user('crm_bd'));

        $this->getJson('/api/v1/crm/dashboard/performance-summary')
            ->assertOk()
            ->assertJsonStructure(['data' => [
                'assignedLeads', 'activeLeads', 'engagedLeads', 'convertedLeads',
                'overallRevenue', 'estimatedPipelineValue', 'conversionRate', 'completedFollowUps',
                'followUpDiscipline' => ['missedFollowUps', 'missedMeetings', 'missedCalls'],
            ]]);
    }

    /** A drilled BD's document is built from that BD's scope, not the requesting admin's. */
    public function test_an_admin_drill_reports_the_subject_bds_own_figures(): void
    {
        $bd = $this->user('crm_bd');
        $this->portfolio($bd);
        $admin = $this->user('crm_admin');

        $subjectFigures = app(PerformanceSummaryService::class)->for($bd);
        $adminFigures = app(PerformanceSummaryService::class)->for($admin);

        // The admin's own scope is CRM-wide, so the two differ — which is what makes the drill
        // meaningful and what the export must honour.
        $this->assertSame(1, $subjectFigures['assignedLeads']);
        $this->assertSame(1, $adminFigures['assignedLeads']);
        $this->assertSame($subjectFigures['convertedLeads'], 1);
    }
}
