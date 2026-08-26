<?php

namespace Tests\Feature\Crm;

use App\Models\User;
use App\Modules\Crm\Services\CrmAccessService;
use App\Modules\Crm\Enums\PerformanceTargetMetric;
use App\Modules\Crm\Enums\PerformanceTargetPeriod;
use App\Modules\Crm\Jobs\RecomputeLeadScoresJob;
use App\Modules\Crm\Models\Activity;
use App\Modules\Crm\Models\BusinessRule;
use App\Modules\Crm\Models\PerformanceTarget;
use App\Modules\Crm\Services\Performance\PerformanceTargetService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Goal Management — performance target CRUD and its consumption by the Pending target surfaces.
 *
 * The RBAC cases carry the most weight. `crm.performance.manage` exists as a permission SEPARATE
 * from `crm.business_rules.manage` precisely because the latter carries a development-only grant to
 * Admin for Lead Scoring; reusing it would have handed Admin performance management by inheritance.
 * `test_admin_keeps_lead_scoring_administration` is the anti-regression guard for that split.
 */
class PerformanceTargetTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/api/v1/crm/settings/performance-targets';

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

    /** @return array<string, mixed> */
    private function payload(User $bd, array $overrides = []): array
    {
        return [
            'user_id' => $bd->id,
            'period_type' => 'month',
            'period_start' => now()->startOfMonth()->toDateString(),
            'metric' => 'converted_leads',
            'target_value' => 10,
            ...$overrides,
        ];
    }

    // ── RBAC ────────────────────────────────────────────────────────────────────────────────────

    public function test_a_super_admin_can_create_a_target(): void
    {
        $bd = $this->user('crm_bd');
        Sanctum::actingAs($this->user('super_admin'));

        $this->postJson(self::URL, $this->payload($bd))
            ->assertCreated()
            ->assertJsonPath('data.targetValue', 10)
            ->assertJsonPath('data.metric', 'converted_leads');
    }

    /**
     * PO decision (superseding the original Super-Admin-only rule): performance management is a
     * MANAGEMENT capability, so Admin holds it too. crm_bd remains excluded — see the test below.
     */
    public function test_an_admin_can_manage_targets(): void
    {
        $bd = $this->user('crm_bd');
        Sanctum::actingAs($this->user('crm_admin'));

        $this->postJson(self::URL, $this->payload($bd))->assertCreated();
        $this->getJson(self::URL)->assertOk();
    }

    /** BD is the one role deliberately excluded: they read their own performance, configure nothing. */
    public function test_a_bd_cannot_manage_targets(): void
    {
        $bd = $this->user('crm_bd');
        Sanctum::actingAs($bd);

        $this->postJson(self::URL, $this->payload($bd))->assertForbidden();
        $this->getJson(self::URL)->assertForbidden();
    }

    public function test_an_unauthenticated_request_is_rejected(): void
    {
        $this->getJson(self::URL)->assertUnauthorized();
    }

    /**
     * ANTI-REGRESSION: the permissions stay SEPARATE even though both management roles now hold
     * both, so Lead Scoring and Performance can be narrowed independently later. Admin still reaches
     * the lead_scoring surface unchanged.
     */
    public function test_admin_keeps_lead_scoring_administration(): void
    {
        $admin = $this->user('crm_admin');

        $this->assertTrue($admin->can('crm.business_rules.manage'));
        $this->assertTrue($admin->can('crm.performance.manage'));

        Sanctum::actingAs($admin);
        // Still reads the seeded lead_scoring baseline — narrowing performance management did not
        // touch this surface. A 403 here would mean the split had regressed Lead Scoring.
        $this->getJson('/api/v1/crm/settings/business-rules/lead_scoring/active')->assertOk();
    }

    /** KPI-definition writes need crm.performance.manage — which Admin now holds. */
    public function test_an_admin_can_write_kpi_definitions(): void
    {
        Sanctum::actingAs($this->user('crm_admin'));

        $this->postJson('/api/v1/crm/settings/business-rules/kpi/draft')->assertSuccessful();
    }

    /** A BD holds neither permission, so the KPI module refuses them at the server. */
    public function test_a_bd_cannot_write_kpi_definitions(): void
    {
        Sanctum::actingAs($this->user('crm_bd'));

        $this->postJson('/api/v1/crm/settings/business-rules/kpi/draft')->assertForbidden();
    }

    // ── KPI definition catalog ──────────────────────────────────────────────────────────────────

    /** The seeded catalog is exactly the frozen M-01…M-06 set, carrying metadata and no scoring. */
    public function test_the_kpi_catalog_is_the_frozen_metric_set(): void
    {
        Sanctum::actingAs($this->user('crm_admin'));

        $rules = $this->getJson('/api/v1/crm/settings/business-rules/kpi/active')
            ->assertOk()
            ->json('data.rules');

        $this->assertSame(
            ['M-01', 'M-02', 'M-03', 'M-04', 'M-05', 'M-06'],
            array_column($rules, 'ruleKey'),
        );

        // A KPI is not worth points and carries no arithmetic sign — null, never a fabricated 0.
        foreach ($rules as $rule) {
            $this->assertNull($rule['pointValue']);
            $this->assertNull($rule['category']);
        }
    }

    /** Metadata IS editable — that is the approved scope of KPI management. */
    public function test_a_kpi_definition_description_can_be_edited(): void
    {
        Sanctum::actingAs($this->user('crm_admin'));

        $this->patchJson('/api/v1/crm/settings/business-rules/kpi/rules/M-01', [
            'description' => 'Assigned Leads — the size of the BD portfolio.',
        ])->assertOk()->assertJsonPath('data.description', 'Assigned Leads — the size of the BD portfolio.');
    }

    /** point_value is NOT a KPI input; the write boundary refuses it rather than storing a fake. */
    public function test_a_kpi_definition_rejects_a_point_value(): void
    {
        Sanctum::actingAs($this->user('crm_admin'));

        $this->patchJson('/api/v1/crm/settings/business-rules/kpi/rules/M-01', ['point_value' => 5])
            ->assertStatus(422);
    }

    /**
     * The KPI catalog publishes through the SAME versioning flow as Lead Scoring. This is the guard
     * for the module-aware publish gate: running the lead_scoring completeness validator over a KPI
     * draft would reject it for the scoring keys it does not have and the nulls it is meant to have.
     */
    public function test_a_kpi_draft_can_be_published(): void
    {
        Sanctum::actingAs($this->user('crm_admin'));

        $this->patchJson('/api/v1/crm/settings/business-rules/kpi/rules/M-01', ['description' => 'Edited.'])
            ->assertOk();

        $this->postJson('/api/v1/crm/settings/business-rules/kpi/publish', ['reason' => 'Clarified M-01.'])
            ->assertOk()
            ->assertJsonPath('data.state', 'active');
    }

    /**
     * ANTI-REGRESSION (Lead Scoring): publishing KPI definitions must NOT recompute lead scores. The
     * recompute job is pinned to a lead_scoring version number, so dispatching it from a KPI publish
     * would recompute every lead against the wrong version. KPI rows feed no score at all.
     */
    public function test_publishing_kpi_definitions_does_not_recompute_lead_scores(): void
    {
        Queue::fake();
        Sanctum::actingAs($this->user('crm_admin'));

        $this->patchJson('/api/v1/crm/settings/business-rules/kpi/rules/M-02', ['description' => 'Edited.'])
            ->assertOk();
        $this->postJson('/api/v1/crm/settings/business-rules/kpi/publish')->assertOk();

        Queue::assertNotPushed(RecomputeLeadScoresJob::class);
    }

    /** The other half of the guard: a lead_scoring publish still recomputes exactly as before. */
    public function test_publishing_lead_scoring_still_recomputes(): void
    {
        Queue::fake();
        Sanctum::actingAs($this->user('crm_admin'));

        $ruleKey = BusinessRule::query()
            ->whereHas('version', fn ($q) => $q->where('module', 'lead_scoring')->where('state', 'active'))
            ->value('rule_key');

        $this->patchJson("/api/v1/crm/settings/business-rules/lead_scoring/rules/{$ruleKey}", [
            'description' => 'Edited for the recompute guard.',
        ])->assertOk();
        $this->postJson('/api/v1/crm/settings/business-rules/lead_scoring/publish')->assertOk();

        Queue::assertPushed(RecomputeLeadScoresJob::class);
    }

    // ── Validation ──────────────────────────────────────────────────────────────────────────────

    public function test_a_target_must_belong_to_an_active_bd(): void
    {
        $admin = $this->user('crm_admin');
        Sanctum::actingAs($this->user('super_admin'));

        $this->postJson(self::URL, $this->payload($admin))->assertStatus(422);
    }

    public function test_an_inactive_bd_is_rejected(): void
    {
        // CRM suspension now lives in `crm_user_access`, not the shared `users.status` column.
        $inactive = $this->user('crm_bd');
        app(CrmAccessService::class)->suspend($inactive);
        Sanctum::actingAs($this->user('super_admin'));

        $this->postJson(self::URL, $this->payload($inactive))->assertStatus(422);
    }

    public function test_an_unknown_metric_or_period_is_rejected(): void
    {
        $bd = $this->user('crm_bd');
        Sanctum::actingAs($this->user('super_admin'));

        $this->postJson(self::URL, $this->payload($bd, ['metric' => 'revenue']))->assertStatus(422);
        $this->postJson(self::URL, $this->payload($bd, ['period_type' => 'week']))->assertStatus(422);
    }

    // ── Identity / lifecycle ────────────────────────────────────────────────────────────────────

    /** Any date inside the period normalises to the period's anchor, so there is one target, not two. */
    public function test_the_period_start_is_normalised_to_the_period_anchor(): void
    {
        $bd = $this->user('crm_bd');
        Sanctum::actingAs($this->user('super_admin'));

        $this->postJson(self::URL, $this->payload($bd, ['period_start' => now()->startOfMonth()->addDays(16)->toDateString()]))
            ->assertCreated();

        $this->assertSame(
            now()->startOfMonth()->toDateString(),
            PerformanceTarget::sole()->period_start->toDateString(),
        );
    }

    /** Re-creating the same subject/period/metric updates the existing row rather than duplicating. */
    public function test_recreating_the_same_target_updates_it(): void
    {
        $bd = $this->user('crm_bd');
        Sanctum::actingAs($this->user('super_admin'));

        $this->postJson(self::URL, $this->payload($bd))->assertCreated();
        $this->postJson(self::URL, $this->payload($bd, ['target_value' => 25]))->assertCreated();

        $this->assertSame(1, PerformanceTarget::count());
        $this->assertSame(25, PerformanceTarget::sole()->target_value);
    }

    public function test_a_target_can_be_updated_and_deleted(): void
    {
        $bd = $this->user('crm_bd');
        Sanctum::actingAs($this->user('super_admin'));
        $id = $this->postJson(self::URL, $this->payload($bd))->json('data.id');

        $this->patchJson(self::URL."/{$id}", ['target_value' => 42])
            ->assertOk()
            ->assertJsonPath('data.targetValue', 42);

        $this->deleteJson(self::URL."/{$id}")->assertOk();
        $this->assertSoftDeleted('crm_performance_targets', ['id' => $id]);
    }

    /** Monthly and quarterly targets are one domain — the same table, discriminated by period. */
    public function test_monthly_and_quarterly_targets_coexist_for_one_bd(): void
    {
        $bd = $this->user('crm_bd');
        Sanctum::actingAs($this->user('super_admin'));

        $this->postJson(self::URL, $this->payload($bd))->assertCreated();
        $this->postJson(self::URL, $this->payload($bd, [
            'period_type' => 'quarter',
            'period_start' => now()->firstOfQuarter()->toDateString(),
            'metric' => 'consultations',
            'target_value' => 5,
        ]))->assertCreated();

        $this->assertSame(2, PerformanceTarget::count());
    }

    // ── Consumption ─────────────────────────────────────────────────────────────────────────────

    /** No target configured → null, so the UI keeps its honest Pending state. */
    public function test_personal_target_is_null_when_none_is_configured(): void
    {
        Sanctum::actingAs($this->user('crm_bd'));

        $this->getJson('/api/v1/crm/dashboard/performance-summary')
            ->assertOk()
            ->assertJsonPath('data.personalTarget', null);
    }

    public function test_personal_target_is_served_when_configured(): void
    {
        $bd = $this->user('crm_bd');
        app(PerformanceTargetService::class)->create($this->payload($bd), null);
        Sanctum::actingAs($bd);

        $this->getJson('/api/v1/crm/dashboard/performance-summary')
            ->assertOk()
            ->assertJsonPath('data.personalTarget.value', 10)
            ->assertJsonPath('data.personalTarget.progress', 0);
    }

    /** A retired target stops applying — the surface returns to Pending rather than a stale goal. */
    public function test_a_retired_target_stops_applying(): void
    {
        $bd = $this->user('crm_bd');
        $target = app(PerformanceTargetService::class)->create($this->payload($bd), null);
        $target->update(['is_active' => false]);
        Sanctum::actingAs($bd);

        $this->getJson('/api/v1/crm/dashboard/performance-summary')
            ->assertOk()
            ->assertJsonPath('data.personalTarget', null);
    }

    /** A target for another month does not leak into this month's figure. */
    public function test_a_target_from_another_period_does_not_apply(): void
    {
        $bd = $this->user('crm_bd');
        app(PerformanceTargetService::class)->create(
            $this->payload($bd, ['period_start' => now()->subMonths(2)->startOfMonth()->toDateString()]),
            null,
        );
        Sanctum::actingAs($bd);

        $this->getJson('/api/v1/crm/dashboard/performance-summary')
            ->assertOk()
            ->assertJsonPath('data.personalTarget', null);
    }

    /** Quarter goals reach the reports payload for a drilled BD, and stay null for All Reports. */
    public function test_quarter_goals_are_served_for_a_drilled_bd_only(): void
    {
        $bd = $this->user('crm_bd');
        app(PerformanceTargetService::class)->create($this->payload($bd, [
            'period_type' => 'quarter',
            'period_start' => now()->firstOfQuarter()->toDateString(),
            'metric' => 'consultations',
            'target_value' => 7,
        ]), null);
        Sanctum::actingAs($this->user('super_admin'));

        $this->getJson('/api/v1/crm/dashboard/reports-analytics?user_id='.$bd->id)
            ->assertOk()
            ->assertJsonPath('data.followThrough.goals.consultations', 7)
            ->assertJsonPath('data.followThrough.goals.leadsContacted', null);

        // All Reports deliberately carries no team aggregate goal.
        $this->getJson('/api/v1/crm/dashboard/reports-analytics')
            ->assertOk()
            ->assertJsonPath('data.followThrough.goals', null);
    }

    /**
     * `targetsPending` must track reality rather than stay hardcoded: it says "no goal applies to this
     * scope", so it goes false once one is configured and stays true for All Reports, which never
     * carries an aggregate target.
     */
    public function test_targets_pending_reflects_whether_a_goal_is_configured(): void
    {
        $bd = $this->user('crm_bd');
        Sanctum::actingAs($this->user('super_admin'));

        $this->getJson('/api/v1/crm/dashboard/reports-analytics?user_id='.$bd->id)
            ->assertJsonPath('data.followThrough.targetsPending', true);

        app(PerformanceTargetService::class)->create($this->payload($bd, [
            'period_type' => 'quarter',
            'period_start' => now()->firstOfQuarter()->toDateString(),
            'metric' => 'confirmed_clients',
            'target_value' => 3,
        ]), null);

        $this->getJson('/api/v1/crm/dashboard/reports-analytics?user_id='.$bd->id)
            ->assertJsonPath('data.followThrough.targetsPending', false);

        $this->getJson('/api/v1/crm/dashboard/reports-analytics')
            ->assertJsonPath('data.followThrough.targetsPending', true);
    }

    // ── Activity trail ──────────────────────────────────────────────────────────────────────────

    public function test_target_mutations_are_recorded_in_the_activity_log(): void
    {
        $bd = $this->user('crm_bd');
        $admin = $this->user('super_admin');
        Sanctum::actingAs($admin);

        $id = $this->postJson(self::URL, $this->payload($bd))->json('data.id');
        $this->patchJson(self::URL."/{$id}", ['target_value' => 12])->assertOk();
        $this->deleteJson(self::URL."/{$id}")->assertOk();

        $events = Activity::query()
            ->where('subject_type', (new PerformanceTarget)->getMorphClass())
            ->pluck('event')
            ->all();

        $this->assertSame(
            ['performance-target.created', 'performance-target.updated', 'performance-target.deleted'],
            $events,
        );
    }

    /** The feed records the fact, not a before/after payload — that stays in the audit trail. */
    public function test_the_activity_carries_context_but_no_diff(): void
    {
        $bd = $this->user('crm_bd');
        Sanctum::actingAs($this->user('super_admin'));
        $this->postJson(self::URL, $this->payload($bd))->assertCreated();

        $properties = Activity::query()
            ->where('event', 'performance-target.created')->sole()->properties;

        $this->assertSame($bd->id, $properties['target_user_id']);
        $this->assertSame('converted_leads', $properties['metric']);
        $this->assertArrayNotHasKey('previous_value', $properties);
    }

    // ── Service unit ────────────────────────────────────────────────────────────────────────────

    public function test_the_service_resolves_the_applying_target(): void
    {
        $bd = $this->user('crm_bd');
        $service = app(PerformanceTargetService::class);
        $service->create($this->payload($bd), null);

        $this->assertSame(10, $service->valueFor(
            $bd->id,
            PerformanceTargetPeriod::MONTH,
            PerformanceTargetMetric::CONVERTED_LEADS,
            Carbon::now(),
        ));
    }
}
