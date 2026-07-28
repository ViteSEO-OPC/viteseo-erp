<?php

namespace Tests\Feature\Crm;

use App\Models\User;
use App\Modules\Crm\Models\Lead;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Server-side filters for the two communication-lifecycle columns, `email_outreach` and
 * `cold_calling`, added to the existing lead list API.
 *
 * Additive by construction: they follow the same single-value shape as `priority`, accept only the
 * values the write side already validates (StoreLeadRequest/UpdateLeadRequest — no new enum), and
 * are absent from a request unless explicitly sent. The unfiltered list is asserted below so the
 * default response can never change.
 */
class LeadListCommunicationFiltersTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    private function actingAsAdmin(): User
    {
        $user = User::factory()->create(['status' => 'active']);
        $user->assignRole('crm_admin');
        Sanctum::actingAs($user);

        return $user;
    }

    /** Creating a lead requires an owner, and the owner must hold the BD role (MustBeBusinessDeveloper). */
    private function businessDeveloper(): User
    {
        $bd = User::factory()->create(['status' => 'active']);
        $bd->assignRole('crm_bd');

        return $bd;
    }

    /** One lead per combination we assert on, so a filter that leaks is immediately visible. */
    private function seedLeads(): void
    {
        Lead::factory()->create([
            'business_name' => 'Replied & Called',
            'email_outreach' => 'replied',
            'cold_calling' => 'called',
        ]);
        Lead::factory()->create([
            'business_name' => 'Replied & Not Called',
            'email_outreach' => 'replied',
            'cold_calling' => 'not-called',
        ]);
        Lead::factory()->create([
            'business_name' => 'Sent & Scheduled',
            'email_outreach' => 'sent',
            'cold_calling' => 'scheduled',
        ]);
        Lead::factory()->create([
            'business_name' => 'None & Not Called',
            'email_outreach' => 'none',
            'cold_calling' => 'not-called',
        ]);
    }

    /** @return array<int, string> business names returned by the list, in response order. */
    private function listNames(array $params = []): array
    {
        $response = $this->getJson('/api/v1/crm/leads?'.http_build_query($params));
        $response->assertOk();

        return array_column($response->json('data'), 'business');
    }

    public function test_unfiltered_list_is_unchanged(): void
    {
        $this->actingAsAdmin();
        $this->seedLeads();

        // Backward compatibility: sending neither filter returns every lead, exactly as before.
        $this->assertCount(4, $this->listNames());
    }

    public function test_email_outreach_filter_narrows_the_list(): void
    {
        $this->actingAsAdmin();
        $this->seedLeads();

        $names = $this->listNames(['email_outreach' => 'replied']);

        $this->assertCount(2, $names);
        $this->assertContains('Replied & Called', $names);
        $this->assertContains('Replied & Not Called', $names);
    }

    public function test_cold_calling_filter_narrows_the_list(): void
    {
        $this->actingAsAdmin();
        $this->seedLeads();

        $names = $this->listNames(['cold_calling' => 'not-called']);

        $this->assertCount(2, $names);
        $this->assertContains('Replied & Not Called', $names);
        $this->assertContains('None & Not Called', $names);
    }

    public function test_the_two_filters_compose(): void
    {
        $this->actingAsAdmin();
        $this->seedLeads();

        $this->assertSame(
            ['Replied & Not Called'],
            $this->listNames(['email_outreach' => 'replied', 'cold_calling' => 'not-called']),
        );
    }

    public function test_they_compose_with_the_existing_filters(): void
    {
        $this->actingAsAdmin();
        Lead::factory()->create([
            'business_name' => 'High Replied',
            'priority' => 'high',
            'email_outreach' => 'replied',
        ]);
        Lead::factory()->create([
            'business_name' => 'Low Replied',
            'priority' => 'low',
            'email_outreach' => 'replied',
        ]);

        $this->assertSame(
            ['High Replied'],
            $this->listNames(['priority' => 'high', 'email_outreach' => 'replied']),
        );
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function invalidFilterProvider(): array
    {
        return [
            // Not a member of the existing whitelist.
            'unknown email_outreach' => ['email_outreach', 'bounced'],
            'unknown cold_calling' => ['cold_calling', 'voicemail'],
            // A value from the OTHER column must not be accepted — the enums are distinct.
            'cross-column value' => ['email_outreach', 'called'],
        ];
    }

    #[DataProvider('invalidFilterProvider')]
    public function test_values_outside_the_existing_whitelist_are_rejected(string $key, string $value): void
    {
        $this->actingAsAdmin();

        $this->getJson('/api/v1/crm/leads?'.http_build_query([$key => $value]))
            ->assertStatus(422)
            ->assertJsonValidationErrors($key);
    }

    /**
     * The write path validates the same two columns against the same enums as the read filter. These
     * guard the Rule::in → Rule::enum swap in Store/UpdateLeadRequest: validation must still accept the
     * identical value set AND must still hand the controller a plain string, so the column keeps storing
     * the raw value rather than a serialized enum.
     */
    public function test_create_accepts_the_enum_values_and_stores_raw_strings(): void
    {
        $this->actingAsAdmin();

        $this->postJson('/api/v1/crm/leads', [
            'business_name' => 'Enum Contract Co',
            'contact_name' => 'Ada Lovelace',
            'assigned_to' => $this->businessDeveloper()->id,
            'email_outreach' => 'opened',
            'cold_calling' => 'scheduled',
        ])->assertCreated();

        $this->assertDatabaseHas('crm_leads', [
            'business_name' => 'Enum Contract Co',
            'email_outreach' => 'opened',
            'cold_calling' => 'scheduled',
        ]);
    }

    public function test_update_accepts_the_enum_values_and_stores_raw_strings(): void
    {
        $this->actingAsAdmin();
        $lead = Lead::factory()->create(['email_outreach' => 'none', 'cold_calling' => 'not-called']);

        $this->putJson("/api/v1/crm/leads/{$lead->id}", [
            'email_outreach' => 'replied',
            'cold_calling' => 'called',
        ])->assertOk();

        $this->assertDatabaseHas('crm_leads', [
            'id' => $lead->id,
            'email_outreach' => 'replied',
            'cold_calling' => 'called',
        ]);
    }

    public function test_create_rejects_values_outside_the_enum(): void
    {
        $this->actingAsAdmin();

        $this->postJson('/api/v1/crm/leads', [
            'business_name' => 'Bad Enum Co',
            'contact_name' => 'Ada Lovelace',
            'assigned_to' => $this->businessDeveloper()->id,
            'email_outreach' => 'bounced',
        ])->assertStatus(422)->assertJsonValidationErrors('email_outreach');
    }

    public function test_assigned_to_filter_narrows_to_one_owner(): void
    {
        $this->actingAsAdmin();
        $bd = $this->businessDeveloper();
        Lead::factory()->create(['business_name' => 'Owned', 'assigned_to' => $bd->id]);
        Lead::factory()->create(['business_name' => 'Other', 'assigned_to' => $this->businessDeveloper()->id]);
        Lead::factory()->create(['business_name' => 'Nobody', 'assigned_to' => null]);

        $this->assertSame(['Owned'], $this->listNames(['assigned_to' => (string) $bd->id]));
    }

    public function test_unassigned_sentinel_returns_only_ownerless_leads(): void
    {
        $this->actingAsAdmin();
        Lead::factory()->create(['business_name' => 'Owned', 'assigned_to' => $this->businessDeveloper()->id]);
        Lead::factory()->create(['business_name' => 'Nobody', 'assigned_to' => null]);

        // `unassigned` maps to assigned_to IS NULL — the state the table renders as "Unassigned".
        $this->assertSame(['Nobody'], $this->listNames(['assigned_to' => 'unassigned']));
    }

    public function test_assigned_to_composes_with_the_other_filters(): void
    {
        $this->actingAsAdmin();
        $bd = $this->businessDeveloper();
        Lead::factory()->create([
            'business_name' => 'Match',
            'assigned_to' => $bd->id,
            'email_outreach' => 'replied',
        ]);
        Lead::factory()->create([
            'business_name' => 'Same owner, wrong outreach',
            'assigned_to' => $bd->id,
            'email_outreach' => 'none',
        ]);

        $this->assertSame(
            ['Match'],
            $this->listNames(['assigned_to' => (string) $bd->id, 'email_outreach' => 'replied']),
        );
    }

    public function test_unknown_owner_is_rejected(): void
    {
        $this->actingAsAdmin();

        // A non-existent id and a non-numeric value both 422, like every other filter — rather than
        // silently returning the unfiltered list.
        foreach (['999999', 'not-a-user'] as $value) {
            $this->getJson('/api/v1/crm/leads?'.http_build_query(['assigned_to' => $value]))
                ->assertStatus(422)
                ->assertJsonValidationErrors('assigned_to');
        }
    }

    /**
     * The status tabs must describe the dataset the table is showing. These pin that the summary
     * narrows with the list filters, and that it deliberately ignores `status` itself so the tabs stay
     * a switcher rather than collapsing to the selected status.
     */
    public function test_status_summary_narrows_with_the_assigned_bd_filter(): void
    {
        $this->actingAsAdmin();
        $bd = $this->businessDeveloper();
        Lead::factory()->create(['assigned_to' => $bd->id, 'status' => 'new']);
        Lead::factory()->create(['assigned_to' => $bd->id, 'status' => 'declined']);
        // Belongs to someone else — must not appear in the selected BD's summary.
        Lead::factory()->create(['assigned_to' => $this->businessDeveloper()->id, 'status' => 'new']);

        $all = $this->getJson('/api/v1/crm/leads/status-summary')->assertOk()->json('data');
        $this->assertSame(3, $all['totalLeads']);
        $this->assertSame(2, $all['statusCounts']['new']);

        $scoped = $this->getJson('/api/v1/crm/leads/status-summary?'.http_build_query([
            'assigned_to' => (string) $bd->id,
        ]))->assertOk()->json('data');

        $this->assertSame(2, $scoped['totalLeads']);
        $this->assertSame(1, $scoped['statusCounts']['new']);
        $this->assertSame(1, $scoped['statusCounts']['declined']);
    }

    public function test_status_summary_matches_the_list_total_for_the_same_filters(): void
    {
        $this->actingAsAdmin();
        $bd = $this->businessDeveloper();
        Lead::factory()->count(3)->create(['assigned_to' => $bd->id]);
        Lead::factory()->count(2)->create(['assigned_to' => $this->businessDeveloper()->id]);

        $params = ['assigned_to' => (string) $bd->id];

        $listTotal = $this->getJson('/api/v1/crm/leads?'.http_build_query($params))
            ->assertOk()->json('meta.total');
        $summaryTotal = $this->getJson('/api/v1/crm/leads/status-summary?'.http_build_query($params))
            ->assertOk()->json('data.totalLeads');

        // The guarantee the tabs rely on: same filters in, same dataset described.
        $this->assertSame($listTotal, $summaryTotal);
        $this->assertSame(3, $summaryTotal);
    }

    public function test_status_summary_ignores_the_status_filter_itself(): void
    {
        $this->actingAsAdmin();
        Lead::factory()->create(['status' => 'new']);
        Lead::factory()->create(['status' => 'declined']);

        // Selecting a tab must not zero the other tabs — they remain the switcher.
        $data = $this->getJson('/api/v1/crm/leads/status-summary?status=new')->assertOk()->json('data');

        $this->assertSame(2, $data['totalLeads']);
        $this->assertSame(1, $data['statusCounts']['new']);
        $this->assertSame(1, $data['statusCounts']['declined']);
    }

    public function test_status_summary_is_ownership_scoped(): void
    {
        // A BD sees only their own leads, exactly as the list does — the summary cannot widen access.
        $bd = User::factory()->create(['status' => 'active']);
        $bd->assignRole('crm_bd');
        Sanctum::actingAs($bd);

        Lead::factory()->create(['assigned_to' => $bd->id, 'status' => 'new']);
        Lead::factory()->create(['assigned_to' => null, 'status' => 'new']);

        $data = $this->getJson('/api/v1/crm/leads/status-summary')->assertOk()->json('data');

        $this->assertSame(1, $data['totalLeads']);
    }

    /**
     * Fixture for the cross-filter sync check: three leads that differ on every filterable axis, so
     * each filter below genuinely narrows the set rather than trivially matching everything.
     *
     * @return array{0: User, 1: User}
     */
    private function seedFilterMatrix(): array
    {
        $bdOne = $this->businessDeveloper();
        $bdTwo = $this->businessDeveloper();

        Lead::factory()->create([
            'business_name' => 'Zzqq Alpha Widgets',
            'assigned_to' => $bdOne->id,
            'priority' => 'high',
            'email_outreach' => 'replied',
            'cold_calling' => 'called',
            'website_status' => 'no-website',
            'industry' => 'Retail',
            'status' => 'new',
        ]);
        Lead::factory()->create([
            'business_name' => 'Beta Corp',
            'assigned_to' => $bdTwo->id,
            'priority' => 'low',
            'email_outreach' => 'none',
            'cold_calling' => 'not-called',
            'website_status' => 'has-website',
            'industry' => 'Legal',
            'status' => 'declined',
        ]);
        Lead::factory()->create([
            'business_name' => 'Gamma Ltd',
            'assigned_to' => $bdOne->id,
            'priority' => 'medium',
            'email_outreach' => 'sent',
            'cold_calling' => 'scheduled',
            'website_status' => 'needs-redesign',
            'industry' => 'Retail',
            'status' => 'interested',
        ]);

        return [$bdOne, $bdTwo];
    }

    /**
     * The contract the Admin status tabs depend on: for EVERY supported list filter, the summary
     * describes the same dataset as the table. Asserted by comparing the summary's total against the
     * list's own meta.total for identical params, and requiring the filter to actually narrow (so a
     * filter silently doing nothing on both sides cannot pass).
     */
    public function test_status_summary_matches_the_list_for_every_supported_filter(): void
    {
        $this->actingAsAdmin();
        [$bdOne] = $this->seedFilterMatrix();

        $unfiltered = $this->getJson('/api/v1/crm/leads/status-summary')->assertOk()->json('data.totalLeads');
        $this->assertSame(3, $unfiltered);

        $cases = [
            'search' => ['search' => 'Zzqq'],
            'assigned_to' => ['assigned_to' => (string) $bdOne->id],
            'priority' => ['priority' => 'high'],
            'email_outreach' => ['email_outreach' => 'replied'],
            'cold_calling' => ['cold_calling' => 'scheduled'],
            'website_status' => ['website_status' => 'has-website'],
            'industry' => ['industry' => 'Retail'],
        ];

        foreach ($cases as $label => $params) {
            $qs = http_build_query($params);

            $listTotal = $this->getJson("/api/v1/crm/leads?{$qs}")->assertOk()->json('meta.total');
            $summaryTotal = $this->getJson("/api/v1/crm/leads/status-summary?{$qs}")
                ->assertOk()->json('data.totalLeads');

            $this->assertSame($listTotal, $summaryTotal, "Summary and list disagree for filter: {$label}");
            $this->assertLessThan($unfiltered, $summaryTotal, "Filter did not narrow anything: {$label}");
        }
    }

    /** Filters must also stay in sync when several are combined, not only one at a time. */
    public function test_status_summary_matches_the_list_for_combined_filters(): void
    {
        $this->actingAsAdmin();
        [$bdOne] = $this->seedFilterMatrix();

        $qs = http_build_query([
            'assigned_to' => (string) $bdOne->id,
            'priority' => 'medium',
            'cold_calling' => 'scheduled',
            'industry' => 'Retail',
        ]);

        $listTotal = $this->getJson("/api/v1/crm/leads?{$qs}")->assertOk()->json('meta.total');
        $summaryTotal = $this->getJson("/api/v1/crm/leads/status-summary?{$qs}")
            ->assertOk()->json('data.totalLeads');

        $this->assertSame(1, $listTotal);
        $this->assertSame($listTotal, $summaryTotal);
    }

    /**
     * The single intentional exception. `status` narrows the table but NOT the summary, so the tabs
     * keep working as a switcher instead of collapsing to the selected status.
     */
    public function test_status_is_the_only_filter_the_summary_ignores(): void
    {
        $this->actingAsAdmin();
        $this->seedFilterMatrix();

        $listTotal = $this->getJson('/api/v1/crm/leads?status=new')->assertOk()->json('meta.total');
        $summaryTotal = $this->getJson('/api/v1/crm/leads/status-summary?status=new')
            ->assertOk()->json('data.totalLeads');

        $this->assertSame(1, $listTotal, 'status should narrow the table');
        $this->assertSame(3, $summaryTotal, 'status must NOT narrow the summary');
    }

    public function test_priority_summary_honours_the_new_filters(): void
    {
        $this->actingAsAdmin();
        Lead::factory()->create(['priority' => 'high', 'email_outreach' => 'replied']);
        Lead::factory()->create(['priority' => 'high', 'email_outreach' => 'none']);

        // The cards share applyLeadListFilters with the list, so they must narrow identically —
        // otherwise a filtered table and its summary cards would disagree.
        $response = $this->getJson('/api/v1/crm/leads/priority-summary?email_outreach=replied');
        $response->assertOk();

        $high = collect($response->json('data'))->firstWhere('priority', 'high');
        $this->assertSame(1, $high['count']);
    }
}
