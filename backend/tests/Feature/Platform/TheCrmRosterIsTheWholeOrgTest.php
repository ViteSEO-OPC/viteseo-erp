<?php

declare(strict_types=1);

namespace Tests\Feature\Platform;

use App\Models\User;
use App\Modules\Crm\Services\CrmAccessService;
use App\Services\OrgOnboardingService;
use App\Support\CapabilityRegistry;
use App\Support\CurrentOrg;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Zerem had two screens for one job, and the older one could not see half of it.
 *
 * `/crm/users` — Team Accounts — lists by Spatie role: a CRM admin is shown
 * accounts holding `crm_bd`. A member in one of the org's OWN roles holds no
 * Spatie role at all, only direct permissions, so they are not merely mislabelled
 * there, they are absent. An admin whose staff are all in custom roles opens
 * Team Accounts to an empty table and no explanation.
 *
 * `/crm/team` lists org membership, so it sees everyone. Merging the two screens
 * onto it is what closes that hole, and it needs the two things the old screen
 * carried and it did not: whether CRM access is suspended, and how many leads a
 * person holds (the reassign action hides itself at zero).
 */
class TheCrmRosterIsTheWholeOrgTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_custom_role_holder_is_on_the_roster_that_the_old_screen_missed(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        $result = app(OrgOnboardingService::class)->createOrg(
            'Bituin Dental', 'Maria', 'maria@bituin.ph', 'password-123', ['crm' => 'full'],
        );
        $org = $result['org'];
        Sanctum::actingAs($result['admin']);

        $caps = CurrentOrg::runAs($org->id, fn (): array => array_slice(array_column(
            array_filter(CapabilityRegistry::forOrg($org->id), fn (array $c): bool => $c['feature'] === 'crm'),
            'key',
        ), 0, 2));

        $roleId = (int) $this->postJson('/api/v1/roles', ['name' => 'Front Desk', 'permissions' => $caps])
            ->assertCreated()->json('data.id');

        $this->postJson('/api/v1/crm/team', [
            'name' => 'Rosa Dela Cruz', 'email' => 'rosa@bituin.ph',
            'role' => "custom:{$roleId}", 'password' => 'RosaDC2026!',
        ])->assertCreated();

        // The old screen: Rosa holds no Spatie role, so the crm_bd filter drops her.
        $old = collect($this->getJson('/api/v1/crm/users')->assertOk()->json('data'))
            ->pluck('email');
        $this->assertNotContains('rosa@bituin.ph', $old->all(), 'This is the hole the merge closes.');

        // The merged screen lists org membership, so she is there and named.
        $members = collect($this->getJson('/api/v1/crm/team')->assertOk()->json('data.members'))
            ->keyBy('email');

        $this->assertArrayHasKey('rosa@bituin.ph', $members->all());
        $this->assertSame('Front Desk', $members['rosa@bituin.ph']['role_label']);
    }

    public function test_the_roster_carries_what_the_old_screen_carried(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        $result = app(OrgOnboardingService::class)->createOrg(
            'Bituin Dental', 'Maria', 'maria@bituin.ph', 'password-123', ['crm' => 'full'],
        );
        Sanctum::actingAs($result['admin']);

        $this->postJson('/api/v1/crm/team', [
            'name' => 'Ben Santos', 'email' => 'ben@bituin.ph',
            'role' => 'crm_bd', 'password' => 'BenS2026!',
        ])->assertCreated();

        $ben = collect($this->getJson('/api/v1/crm/team')->assertOk()->json('data.members'))
            ->firstWhere('email', 'ben@bituin.ph');

        // Without these two the merged row cannot show suspension or offer the lead
        // handover, and merging would have cost Zerem features rather than saved a click.
        $this->assertFalse($ben['suspended']);
        $this->assertSame(0, $ben['lead_count']);

        // Asserting only the false case would pass against a hardcoded false, so
        // suspend him through the service that owns it and read the row back.
        $benUser = User::where('email', 'ben@bituin.ph')->firstOrFail();
        CurrentOrg::runAs($result['org']->id, fn () => app(CrmAccessService::class)->suspend($benUser));

        $again = collect($this->getJson('/api/v1/crm/team')->assertOk()->json('data.members'))
            ->firstWhere('email', 'ben@bituin.ph');

        $this->assertTrue($again['suspended'], 'A suspended colleague must read as suspended on the merged row.');
    }

    public function test_an_admin_can_act_on_the_member_they_just_hired(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        $result = app(OrgOnboardingService::class)->createOrg(
            'Bituin Dental', 'Maria', 'maria@bituin.ph', 'password-123', ['crm' => 'full'],
        );
        $org = $result['org'];
        Sanctum::actingAs($result['admin']);

        $caps = CurrentOrg::runAs($org->id, fn (): array => array_slice(array_column(
            array_filter(CapabilityRegistry::forOrg($org->id), fn (array $c): bool => $c['feature'] === 'crm'),
            'key',
        ), 0, 2));

        $roleId = (int) $this->postJson('/api/v1/roles', ['name' => 'Front Desk', 'permissions' => $caps])
            ->assertCreated()->json('data.id');

        $id = (int) $this->postJson('/api/v1/crm/team', [
            'name' => 'Rosa Dela Cruz', 'email' => 'rosa@bituin.ph',
            'role' => "custom:{$roleId}", 'password' => 'RosaDC2026!',
        ])->assertCreated()->json('data.id');

        // Seeing her was half of it. The write policy asked the same question the
        // list used to -- "does she hold crm_bd?" -- so every action against the
        // person an admin had just hired came back 403.
        $this->patchJson("/api/v1/crm/users/{$id}", ['name' => 'Rosa D. Cruz'])->assertOk();

        $this->assertSame('Rosa D. Cruz', User::findOrFail($id)->name);
    }

    public function test_an_admin_still_cannot_touch_another_admin(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        $result = app(OrgOnboardingService::class)->createOrg(
            'Bituin Dental', 'Maria', 'maria@bituin.ph', 'password-123', ['crm' => 'full'],
        );
        Sanctum::actingAs($result['admin']);

        $id = (int) $this->postJson('/api/v1/crm/team', [
            'name' => 'Ben Santos', 'email' => 'ben@bituin.ph',
            'role' => 'crm_admin', 'password' => 'BenS2026!',
        ])->assertCreated()->json('data.id');

        // The half of the rule that is the whole point of it. An admin may appoint a
        // co-admin; they may not then rename, suspend or delete one.
        $this->patchJson("/api/v1/crm/users/{$id}", ['name' => 'Renamed'])->assertForbidden();
        $this->assertSame('Ben Santos', User::findOrFail($id)->name);
    }
}
