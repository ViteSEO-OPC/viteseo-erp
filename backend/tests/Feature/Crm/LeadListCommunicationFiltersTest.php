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
