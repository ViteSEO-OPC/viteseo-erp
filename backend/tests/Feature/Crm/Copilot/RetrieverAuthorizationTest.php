<?php

namespace Tests\Feature\Crm\Copilot;

use App\Models\User;
use App\Modules\Crm\Models\CopilotSetting;
use App\Modules\Crm\Models\Lead;
use App\Modules\Crm\Models\NerbyProviderSetting;
use App\Modules\Crm\Models\PlannerEvent;
use App\Modules\Crm\Services\Copilot\Retrieval\CapabilityRegistry;
use App\Modules\Crm\Services\Copilot\Retrieval\DashboardKpiRetriever;
use App\Modules\Crm\Services\Copilot\Retrieval\FactScope;
use App\Modules\Crm\Services\Copilot\Retrieval\FollowUpRetriever;
use App\Modules\Crm\Services\Copilot\Retrieval\KnowledgeRetriever;
use App\Modules\Crm\Services\Copilot\Retrieval\LeadPortfolioRetriever;
use App\Modules\Crm\Services\Copilot\Retrieval\PerformanceRetriever;
use App\Modules\Crm\Services\Copilot\Retrieval\RetrievedFacts;
use App\Modules\Crm\Services\Copilot\Retrieval\RoleExplainerRetriever;
use App\Modules\Crm\Services\Copilot\Retrieval\ScoringRulesRetriever;
use App\Modules\Crm\Services\CrmAccessService;
use App\Support\CurrentOrg;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Every capability runs AS the authenticated server user, inside that user's org, under the same
 * ownership scopes and permissions as the screen it mirrors — and returns no per-lead or personal data.
 */
class RetrieverAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    private int $orgId;

    private int $otherOrgId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        // The base TestCase already runs as its entitled tenant (users join it on creation); the
        // other org is a bare second tenant whose rows must never be counted.
        $this->orgId = $this->tenant()->id;
        $this->otherOrgId = $this->org('Tenant B');
        CurrentOrg::set($this->orgId);
    }

    private function org(string $name): int
    {
        return (int) DB::table('orgs')->insertGetId(['name' => $name, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
    }

    private function user(string $role): User
    {
        $user = User::factory()->create(['status' => 'active']);
        $user->assignRole($role);

        return $user;
    }

    private function lead(?User $owner, array $attributes = []): Lead
    {
        return Lead::factory()->create([
            'assigned_to' => $owner?->id,
            'status' => 'new',
            'priority' => 'high',
            'website_status' => 'no-website',
            'business_name' => 'Secret Bakery '.uniqid(),
            'contact_name' => 'Juan Dela Cruz',
            'email' => 'juan@secret-bakery.example',
            'phone' => '+63 917 555 0101',
            ...$attributes,
        ]);
    }

    private function facts(RetrievedFacts $r): array
    {
        return array_column($r->facts, 'value', 'name');
    }

    public function test_a_bd_sees_only_their_own_leads_and_an_admin_sees_the_whole_org(): void
    {
        $bd = $this->user('crm_bd');
        $other = $this->user('crm_bd');
        $this->lead($bd);
        $this->lead($bd, ['status' => 'interested']);
        $this->lead($other);
        $this->lead(null);

        $portfolio = app(LeadPortfolioRetriever::class);
        $this->assertSame(2, $this->facts($portfolio->retrieve($bd, 'q'))['total_leads']);
        $this->assertSame(['interested' => 1, 'new' => 1], $this->facts($portfolio->retrieve($bd, 'q'))['leads_by_status']);
        $this->assertSame(4, $this->facts($portfolio->retrieve($this->user('crm_admin'), 'q'))['total_leads']);
        $this->assertSame(4, $this->facts($portfolio->retrieve($this->user('super_admin'), 'q'))['total_leads']);
    }

    /**
     * The scope label is what lets the validator refuse "your 436 leads" for an Admin: it must say
     * "organization" exactly when visibleTo shows the whole CRM, and "own" exactly when it does not.
     */
    public function test_every_data_capability_labels_whose_figures_it_returns(): void
    {
        $bd = $this->user('crm_bd');
        $this->lead($bd);
        $this->lead($this->user('crm_bd'));

        foreach (['crm_bd' => FactScope::OWN, 'crm_admin' => FactScope::ORGANIZATION, 'super_admin' => FactScope::ORGANIZATION] as $role => $scope) {
            $user = $role === 'crm_bd' ? $bd : $this->user($role);
            // The label agrees with what the query actually counted.
            $total = $this->facts(app(LeadPortfolioRetriever::class)->retrieve($user, 'q'))['total_leads'];
            $this->assertSame($scope === FactScope::OWN ? 1 : 2, $total, $role);

            foreach ([LeadPortfolioRetriever::class, DashboardKpiRetriever::class, FollowUpRetriever::class, PerformanceRetriever::class] as $class) {
                $this->assertSame($scope, app($class)->retrieve($user, 'q')->scope, "{$role} {$class}");
            }
        }

        $admin = $this->user('crm_admin');
        foreach ([ScoringRulesRetriever::class, KnowledgeRetriever::class, RoleExplainerRetriever::class] as $class) {
            $this->assertSame(FactScope::REFERENCE, app($class)->retrieve($admin, 'scoring')->scope, $class);
        }
    }

    public function test_another_organisations_leads_are_never_counted(): void
    {
        $admin = $this->user('crm_admin');
        $this->lead($admin);
        $this->lead($admin, ['org_id' => $this->otherOrgId]);
        $this->lead(null, ['org_id' => $this->otherOrgId]);

        $this->assertSame(1, $this->facts(app(LeadPortfolioRetriever::class)->retrieve($admin, 'q'))['total_leads']);
        $this->assertSame(1, $this->facts(app(DashboardKpiRetriever::class)->retrieve($admin, 'q'))['total_leads']);
        $this->assertSame(1, $this->facts(app(PerformanceRetriever::class)->retrieve($admin, 'q'))['assigned_leads']);
    }

    public function test_follow_ups_are_scoped_to_the_bd(): void
    {
        $this->travelTo(now()->setTime(4, 0));
        $bd = $this->user('crm_bd');
        $other = $this->user('crm_bd');
        foreach ([[$bd, now()->subDays(2)], [$bd, now()->addDays(3)], [$other, now()->subDay()]] as [$owner, $when]) {
            PlannerEvent::create([
                'title' => 'Call back', 'event_type' => 'follow-up', 'status' => 'todo', 'starts_at' => $when,
                'lead_id' => $this->lead($owner)->id, 'assigned_to' => $owner->id, 'created_by' => $owner->id,
            ]);
        }

        $mine = $this->facts(app(FollowUpRetriever::class)->retrieve($bd, 'q'));
        $this->assertSame(['overdue' => 1, 'due_today' => 0, 'upcoming' => 1], $mine);
        $all = $this->facts(app(FollowUpRetriever::class)->retrieve($this->user('crm_admin'), 'q'));
        $this->assertSame(2, $all['overdue']);
    }

    public function test_each_capability_mirrors_the_permission_of_its_screen(): void
    {
        $bd = $this->user('crm_bd');
        $admin = $this->user('crm_admin');
        $noRole = User::factory()->create(['status' => 'active']);

        // Scoring rules are the Business Rules screen: Admin yes, BD no.
        $this->assertTrue(app(ScoringRulesRetriever::class)->permits($admin));
        $this->assertFalse(app(ScoringRulesRetriever::class)->permits($bd));

        // Dashboard-backed capabilities need crm.dashboard.view; a user without it gets none of them.
        foreach ([DashboardKpiRetriever::class, PerformanceRetriever::class, FollowUpRetriever::class, LeadPortfolioRetriever::class] as $class) {
            $this->assertTrue(app($class)->permits($bd), $class);
            $this->assertFalse(app($class)->permits($noRole), $class);
        }
    }

    public function test_performance_is_always_the_callers_own_even_if_the_request_names_another_user(): void
    {
        config(['copilot.ai.enabled' => true]);
        CopilotSetting::query()->create(['ai_enabled' => true]);
        NerbyProviderSetting::query()->create([
            'provider' => 'openai', 'enabled' => true, 'api_key' => 'sk-or-v1-'.str_repeat('c', 24),
            'key_last4' => 'cccc', 'last_test_status' => 'ok', 'last_tested_at' => now(),
        ]);
        Http::fake(['*' => Http::response(['choices' => [['message' => ['content' => '{"answerable":false,"answer":"","sources":[]}']]]])]);

        $bd = $this->user('crm_bd');
        $other = $this->user('crm_bd');
        $this->lead($bd);
        $this->lead($other);
        $this->lead($other);
        Sanctum::actingAs($bd);

        $this->postJson('/api/v1/crm/copilot/ask', ['question' => 'how is my performance vs target', 'user_id' => $other->id, 'org_id' => $this->otherOrgId, 'role' => 'super_admin'])->assertOk();

        $packet = json_decode(Http::recorded()->first()[0]['messages'][1]['content'], true);
        $sources = array_column($packet['data']['facts'], null, 'source');
        $this->assertSame(1, array_column($sources['performance']['facts'], 'value', 'name')['assigned_leads']);
        $this->assertSame(1, array_column($sources['lead_portfolio']['facts'], 'value', 'name')['total_leads']);
    }

    public function test_a_suspended_user_is_refused_before_any_capability_runs(): void
    {
        $bd = $this->user('crm_bd');
        app(CrmAccessService::class)->suspend($bd);
        Http::fake();
        Sanctum::actingAs($bd);

        $this->postJson('/api/v1/crm/copilot/ask', ['question' => 'overview'])->assertForbidden();
        $this->getJson('/api/v1/crm/copilot/config')->assertForbidden();
        Http::assertNothingSent();
    }

    public function test_no_capability_ever_returns_lead_names_contacts_or_ids(): void
    {
        $admin = $this->user('super_admin');
        $lead = $this->lead($admin);
        foreach (app(CapabilityRegistry::class)->keys() as $key) {
            $capability = app(CapabilityRegistry::class)->get($key);
            $raw = json_encode($capability->retrieve($admin, 'roles scoring performance leads'));
            foreach ([$lead->business_name, 'Juan Dela Cruz', 'juan@secret-bakery.example', '555 0101', '"lead_id"', '"assigned_to"', '"user_id"'] as $secret) {
                $this->assertStringNotContainsString($secret, $raw, "{$key} leaked {$secret}");
            }
        }
    }

    public function test_the_role_explainer_reports_only_the_callers_own_roles(): void
    {
        $bd = $this->user('crm_bd');
        $this->user('super_admin');

        $facts = $this->facts(app(RoleExplainerRetriever::class)->retrieve($bd, 'what can I access'));
        $this->assertSame(['crm_bd'], $facts['your_roles']);
        $this->assertNotContains('crm.business_rules.view', $facts['your_crm_permissions']);
        $this->assertContains('crm.leads.view', $facts['your_crm_permissions']);
    }
}
