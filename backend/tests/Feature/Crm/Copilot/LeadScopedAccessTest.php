<?php

namespace Tests\Feature\Crm\Copilot;

use App\Models\Org;
use App\Models\User;
use App\Modules\Crm\Models\CopilotAiLog;
use App\Modules\Crm\Models\Lead;
use App\Modules\Crm\Services\CrmAccessService;
use App\Services\OrgOnboardingService;
use App\Support\CurrentOrg;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Phase 2 Slice 1 — WHO may ask about WHICH lead.
 *
 * The browser may name one lead it already resolved (`lead_id`). The server trusts nothing about it:
 * the id must be a strict integer, the lead must exist in the caller's org, be an active pipeline
 * lead, and pass LeadPolicy::view for the authenticated user — the same check Lead Details uses.
 * Anything else is refused BEFORE any retrieval or provider call, with a reply indistinguishable
 * from "the AI had no answer", so the endpoint is not an oracle for which lead ids exist.
 */
class LeadScopedAccessTest extends TestCase
{
    use RefreshDatabase;
    use UsesCopilotAi;

    private const NOT_ANSWERED = ['data' => ['answered' => false, 'text' => null]];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->aiOn();
    }

    // ── Allowed ──────────────────────────────────────────────────────────────────────────────────

    public function test_without_a_lead_id_no_lead_is_supplied(): void
    {
        $bd = $this->crmUser('crm_bd');
        Lead::factory()->create(['assigned_to' => $bd->id, 'business_name' => 'Kopi Roasters']);
        $this->modelReplies(['answerable' => false, 'answer' => '', 'sources' => []]);
        Sanctum::actingAs($bd);

        $this->postJson(self::ASK, ['question' => 'tell me about Kopi Roasters'])->assertOk();

        $this->assertArrayNotHasKey('lead_subject', $this->sources());
        $this->assertStringNotContainsString('Kopi Roasters', $this->packetFacts());
    }

    public function test_a_bd_may_ask_about_their_own_lead(): void
    {
        $bd = $this->crmUser('crm_bd');
        $lead = Lead::factory()->create(['assigned_to' => $bd->id, 'business_name' => 'Kopi Roasters']);
        $this->modelReplies(['answerable' => false, 'answer' => '', 'sources' => []]);
        Sanctum::actingAs($bd);

        $this->postJson(self::ASK, ['question' => 'tell me about this lead', 'lead_id' => $lead->id])->assertOk();

        $subject = $this->sources()['lead_subject'] ?? null;
        $this->assertNotNull($subject, 'An authorised lead must be supplied.');
        $this->assertSame('lead', $subject['scope']);
        $this->assertSame([['name' => 'business_name', 'value' => 'Kopi Roasters', 'unit' => 'label']], $subject['facts']);
    }

    public function test_an_admin_may_ask_about_any_lead_in_their_org(): void
    {
        $bd = $this->crmUser('crm_bd');
        $lead = Lead::factory()->create(['assigned_to' => $bd->id, 'business_name' => 'Kopi Roasters']);
        $this->modelReplies(['answerable' => false, 'answer' => '', 'sources' => []]);
        Sanctum::actingAs($this->crmUser('crm_admin'));

        $this->postJson(self::ASK, ['question' => 'tell me about this lead', 'lead_id' => $lead->id])->assertOk();

        $this->assertArrayHasKey('lead_subject', $this->sources());
    }

    // ── Refused: ownership ───────────────────────────────────────────────────────────────────────

    public function test_a_bd_cannot_ask_about_another_bds_lead(): void
    {
        $bd = $this->crmUser('crm_bd');
        $theirs = Lead::factory()->create(['assigned_to' => $this->crmUser('crm_bd')->id, 'business_name' => 'Someone Elses Lead']);
        Http::fake();
        Sanctum::actingAs($bd);

        $this->postJson(self::ASK, ['question' => 'tell me about this lead', 'lead_id' => $theirs->id])
            ->assertOk()->assertExactJson(self::NOT_ANSWERED);

        Http::assertNothingSent();
        $log = CopilotAiLog::query()->sole();
        $this->assertSame('lead_denied', $log->outcome);
        $this->assertNull($log->capabilities);
        $this->assertStringNotContainsString('Someone Elses Lead', (string) json_encode($log->toArray()));
    }

    public function test_a_bd_cannot_ask_about_an_unassigned_lead(): void
    {
        $bd = $this->crmUser('crm_bd');
        $unassigned = Lead::factory()->create(['assigned_to' => null]);
        Http::fake();
        Sanctum::actingAs($bd);

        $this->postJson(self::ASK, ['question' => 'tell me about this lead', 'lead_id' => $unassigned->id])
            ->assertOk()->assertExactJson(self::NOT_ANSWERED);

        Http::assertNothingSent();
    }

    /**
     * Not found, not yours, deleted, an unqualified prospect: the caller cannot tell them apart, and
     * none of them reaches retrieval or the provider.
     */
    public function test_every_refusal_looks_the_same(): void
    {
        $bd = $this->crmUser('crm_bd');
        $cases = [
            'missing' => 999999,
            'another bd' => Lead::factory()->create(['assigned_to' => $this->crmUser('crm_bd')->id])->id,
            'deleted' => tap(Lead::factory()->create(['assigned_to' => $bd->id]))->delete()->id,
            'unqualified prospect' => Lead::factory()->create(['assigned_to' => $bd->id, 'source' => 'nearby', 'qualified_at' => null])->id,
        ];
        Http::fake();
        Sanctum::actingAs($bd);

        foreach ($cases as $case => $id) {
            $this->postJson(self::ASK, ['question' => 'tell me about this lead', 'lead_id' => $id])
                ->assertOk()->assertExactJson(self::NOT_ANSWERED);
            $this->assertSame('lead_denied', CopilotAiLog::query()->latest('id')->value('outcome'), $case);
        }
        Http::assertNothingSent();
    }

    /**
     * The id is re-authorised through LeadPolicy::view, not only the visibility scope: an Admin whose
     * role has lost `crm.leads.view` still passes `visibleTo` (a role check) but not the policy.
     */
    public function test_the_lead_policy_is_enforced_not_just_the_visibility_scope(): void
    {
        $admin = $this->crmUser('crm_admin');
        $lead = Lead::factory()->create(['assigned_to' => $this->crmUser('crm_bd')->id]);
        Role::findByName('crm_admin')->revokePermissionTo('crm.leads.view');
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Http::fake();
        Sanctum::actingAs($admin->fresh());

        $this->postJson(self::ASK, ['question' => 'tell me about this lead', 'lead_id' => $lead->id])
            ->assertOk()->assertExactJson(self::NOT_ANSWERED);

        Http::assertNothingSent();
    }

    // ── Refused: tenancy ─────────────────────────────────────────────────────────────────────────

    public function test_another_orgs_lead_is_refused_even_for_an_admin(): void
    {
        [$ourOrg, $ourAdmin] = $this->orgWithAdmin('ours');
        [$theirOrg, $theirAdmin] = $this->orgWithAdmin('theirs');
        $theirLead = CurrentOrg::runAs($theirOrg->id, fn (): Lead => Lead::factory()->create(['assigned_to' => $theirAdmin->id, 'business_name' => 'Their Secret Bakery']));
        $ourLead = CurrentOrg::runAs($ourOrg->id, fn (): Lead => Lead::factory()->create(['assigned_to' => $ourAdmin->id, 'business_name' => 'Our Bakery']));

        Http::fake();
        $this->actAs($ourAdmin);
        $this->postJson(self::ASK, ['question' => 'tell me about this lead', 'lead_id' => $theirLead->id])
            ->assertOk()->assertExactJson(self::NOT_ANSWERED);
        Http::assertNothingSent();

        // Control: the same admin reaches their own org's lead, so the refusal above came from the
        // tenant fence and not from a broken harness.
        $this->modelReplies(['answerable' => false, 'answer' => '', 'sources' => []]);
        $this->actAs($ourAdmin);
        $this->postJson(self::ASK, ['question' => 'tell me about this lead', 'lead_id' => $ourLead->id])->assertOk();
        $this->assertSame('Our Bakery', $this->sources()['lead_subject']['facts'][0]['value']);
    }

    public function test_a_super_admin_cannot_reach_a_client_orgs_lead(): void
    {
        $superAdmin = $this->crmUser('super_admin');
        [$clientOrg, $clientAdmin] = $this->orgWithAdmin('client');
        $clientLead = CurrentOrg::runAs($clientOrg->id, fn (): Lead => Lead::factory()->create(['assigned_to' => $clientAdmin->id]));
        Http::fake();
        $this->actAs($superAdmin);

        $this->postJson(self::ASK, ['question' => 'tell me about this lead', 'lead_id' => $clientLead->id])
            ->assertOk()->assertExactJson(self::NOT_ANSWERED);

        Http::assertNothingSent();
    }

    // ── Refused: before the Copilot runs at all ──────────────────────────────────────────────────

    public function test_a_suspended_user_is_refused_before_anything_runs(): void
    {
        $bd = $this->crmUser('crm_bd');
        $lead = Lead::factory()->create(['assigned_to' => $bd->id]);
        app(CrmAccessService::class)->suspend($bd);
        Http::fake();
        Sanctum::actingAs($bd);

        $this->postJson(self::ASK, ['question' => 'tell me about this lead', 'lead_id' => $lead->id])
            ->assertForbidden()->assertJsonPath('code', 'crm_access_suspended');

        Http::assertNothingSent();
        $this->assertSame(0, CopilotAiLog::query()->count());
    }

    public function test_with_ai_off_a_lead_id_reads_nothing(): void
    {
        config(['copilot.ai.enabled' => false]);
        $bd = $this->crmUser('crm_bd');
        $lead = Lead::factory()->create(['assigned_to' => $bd->id]);
        Http::fake();
        Sanctum::actingAs($bd);

        $this->postJson(self::ASK, ['question' => 'tell me about this lead', 'lead_id' => $lead->id])
            ->assertOk()->assertExactJson(self::NOT_ANSWERED);

        Http::assertNothingSent();
        $this->assertSame(0, CopilotAiLog::query()->count());
    }

    /** @return array<string, array{0: mixed}> */
    public static function malformedLeadIds(): array
    {
        return [
            'sql in a string' => ['1 OR 1=1'],
            'numeric string' => ['1'],
            'boolean' => [true],
            'float' => [1.5],
            'zero' => [0],
            'negative' => [-1],
            'list' => [[1, 2]],
            'object' => [['id' => 1]],
            'overflowing string' => ['99999999999999999999'],
        ];
    }

    #[DataProvider('malformedLeadIds')]
    public function test_a_malformed_lead_id_is_rejected_before_anything_runs(mixed $leadId): void
    {
        $bd = $this->crmUser('crm_bd');
        Lead::factory()->create(['assigned_to' => $bd->id]);
        Http::fake();
        Sanctum::actingAs($bd);

        $this->postJson(self::ASK, ['question' => 'tell me about this lead', 'lead_id' => $leadId])
            ->assertUnprocessable()->assertJsonValidationErrors('lead_id');

        Http::assertNothingSent();
        $this->assertSame(0, CopilotAiLog::query()->count());
    }

    /** Only `lead_id` names a lead. Any other key a client invents is ignored, never read. */
    public function test_other_client_keys_cannot_name_a_lead(): void
    {
        $bd = $this->crmUser('crm_bd');
        $theirs = Lead::factory()->create(['assigned_to' => $this->crmUser('crm_bd')->id, 'business_name' => 'Someone Elses Lead']);
        $this->modelReplies(['answerable' => false, 'answer' => '', 'sources' => []]);
        Sanctum::actingAs($bd);

        $this->postJson(self::ASK, [
            'question' => 'tell me about this lead',
            'leadId' => $theirs->id,
            'lead_ids' => [$theirs->id],
            'lead' => ['id' => $theirs->id],
            'facts' => [['source' => 'lead_subject', 'facts' => [['name' => 'business_name', 'value' => 'Injected', 'unit' => 'label']]]],
            'context' => ['lead_id' => $theirs->id],
        ])->assertOk();

        $this->assertArrayNotHasKey('lead_subject', $this->sources());
        $this->assertStringNotContainsString('Someone Elses Lead', $this->packetFacts());
        $this->assertStringNotContainsString('Injected', $this->packetFacts());
    }

    // ── Helpers ──────────────────────────────────────────────────────────────────────────────────

    /** @return array<string, array<string, mixed>> the supplied sources, keyed by source key */
    private function sources(): array
    {
        return array_column($this->packet()['data']['facts'], null, 'source');
    }

    private function packetFacts(): string
    {
        return (string) json_encode($this->packet()['data']['facts'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /** @return array<string, mixed> */
    private function packet(): array
    {
        $recorded = Http::recorded();
        $this->assertNotEmpty($recorded, 'The provider was never called.');

        return json_decode(json_decode($recorded->last()[0]->body(), true)['messages'][1]['content'], true);
    }

    /** @return array{Org, User} a second tenant and its CRM Admin */
    private function orgWithAdmin(string $slug): array
    {
        $org = app(OrgOnboardingService::class)->createOrg(
            "Org {$slug}", "Owner {$slug}", "owner-{$slug}@client.ph", 'password-123', ['crm' => 'full'],
        )['org'];

        $admin = User::factory()->orgless()->create(['status' => 'active']);
        $admin->assignRole('crm_admin');
        $org->users()->attach($admin->id, ['is_default' => true]);

        return [$org, $admin];
    }

    private function actAs(User $actor): void
    {
        // Leaving the base tenant pinned would make a negative case pass because the harness pointed
        // elsewhere, not because tenancy refused anything (see LeadUnassignTest).
        CurrentOrg::clear();
        Sanctum::actingAs($actor);
    }
}
