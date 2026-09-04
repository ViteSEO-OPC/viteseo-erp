<?php

declare(strict_types=1);

namespace Tests\Feature\Platform;

use App\Models\CustomRole;
use App\Modules\Crm\Rules\MustBeBusinessDeveloper;
use App\Models\User;
use App\Services\OrgOnboardingService;
use App\Support\CapabilityRegistry;
use App\Support\CurrentOrg;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Building a role and staffing it are one feature, not two.
 *
 * The role builder shipped working and the team screens shipped working, and the
 * loop between them was still open: the three module team endpoints validated
 * `role` with `in:<presets>`, so a custom role was rejected on the way in. An
 * admin could define "Front Desk", see it listed, and have no way to put a single
 * person in it — which is the whole reason the builder exists.
 *
 * The platform TeamController had accepted `custom:<id>` all along. The half the
 * operator actually uses was the half that could not.
 */
class ARoleIsNoUseIfNobodyCanHoldItTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{\App\Models\Org, User} */
    private function clientOn(array $systems, string $email): array
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        $r = app(OrgOnboardingService::class)->createOrg(
            'Client '.$email, 'Owner', $email, 'password-123', $systems,
        );

        return [$r['org'], $r['admin']];
    }

    /** @return array{int, list<string>} */
    private function buildRole(int $orgId, string $feature, string $name): array
    {
        $caps = CurrentOrg::runAs($orgId, fn (): array => array_slice(array_column(
            array_filter(CapabilityRegistry::forOrg($orgId), fn (array $c): bool => $c['feature'] === $feature),
            'key',
        ), 0, 2));

        $id = (int) $this->postJson('/api/v1/roles', ['name' => $name, 'permissions' => $caps])
            ->assertCreated()->json('data.id');

        return [$id, $caps];
    }

    public function test_a_custom_role_can_be_given_to_a_new_crm_member(): void
    {
        [$org, $admin] = $this->clientOn(['crm' => 'full'], 'crm-loop@client.ph');
        Sanctum::actingAs($admin);

        [$roleId, $caps] = $this->buildRole($org->id, 'crm', 'Front Desk');

        $this->postJson('/api/v1/crm/team', [
            'name' => 'Rosa Dela Cruz',
            'email' => 'rosa@client.ph',
            'role' => "custom:{$roleId}",
            'password' => 'RosaDC2026!',
        ])->assertCreated();

        $rosa = User::where('email', 'rosa@client.ph')->firstOrFail();

        // Exactly the ticked capabilities, and no preset role smuggled in beside them.
        $this->assertSame($caps, $rosa->getAllPermissions()->pluck('name')->sort()->values()->all());
        $this->assertEmpty($rosa->getRoleNames());
    }

    public function test_the_same_holds_for_hr_and_projects(): void
    {
        [$org, $admin] = $this->clientOn(['hr' => 'full', 'pms' => 'full'], 'multi-loop@client.ph');
        Sanctum::actingAs($admin);

        foreach ([['hr', '/api/v1/hr/team', 'nurse@client.ph'], ['pms', '/api/v1/projects/team', 'pm@client.ph']] as [$feature, $endpoint, $email]) {
            [$roleId, $caps] = $this->buildRole($org->id, $feature, "Role for {$feature}");

            $this->postJson($endpoint, [
                'name' => 'Member '.$feature,
                'email' => $email,
                'role' => "custom:{$roleId}",
                'password' => 'Member2026!',
            ])->assertCreated();

            $member = User::where('email', $email)->firstOrFail();
            $this->assertSame($caps, $member->getAllPermissions()->pluck('name')->sort()->values()->all());
        }
    }

    public function test_another_orgs_custom_role_cannot_be_borrowed(): void
    {
        [$a, $adminA] = $this->clientOn(['crm' => 'full'], 'a-loop@client.ph');
        Sanctum::actingAs($adminA);
        [$roleId] = $this->buildRole($a->id, 'crm', 'A Only');

        [, $adminB] = $this->clientOn(['crm' => 'full'], 'b-loop@client.ph');
        Sanctum::actingAs($adminB);

        // The id is real, and belongs to somebody else. The model's own org scope is
        // what makes it invisible here, so this is refused as "not available" rather
        // than found and applied.
        $this->postJson('/api/v1/crm/team', [
            'name' => 'Borrowed', 'email' => 'borrowed@client.ph',
            'role' => "custom:{$roleId}", 'password' => 'Borrowed2026!',
        ])->assertStatus(422)->assertJsonValidationErrors('role');

        $this->assertDatabaseMissing('users', ['email' => 'borrowed@client.ph']);
    }

    public function test_re_roling_to_a_preset_clears_the_custom_capabilities(): void
    {
        [$org, $admin] = $this->clientOn(['crm' => 'full'], 'rerole@client.ph');
        Sanctum::actingAs($admin);

        [$roleId] = $this->buildRole($org->id, 'crm', 'Temp Role');

        $id = (int) $this->postJson('/api/v1/crm/team', [
            'name' => 'Mover', 'email' => 'mover@client.ph',
            'role' => "custom:{$roleId}", 'password' => 'Mover2026!',
        ])->assertCreated()->json('data.id');

        $this->putJson("/api/v1/crm/team/{$id}", ['role' => 'crm_bd'])->assertOk();

        $mover = User::findOrFail($id);
        $this->assertTrue($mover->hasRole('crm_bd'));
        // The old role's direct grants must not linger beside the preset.
        $this->assertEmpty($mover->getDirectPermissions());
        $this->assertNotNull(CustomRole::query()->find($roleId), 'The role itself was deleted, not just unassigned.');
    }

    public function test_the_team_screen_can_name_the_role_a_member_holds(): void
    {
        [$org, $admin] = $this->clientOn(['crm' => 'full', 'hr' => 'full', 'pms' => 'full'], 'label@client.ph');
        Sanctum::actingAs($admin);

        [$roleId] = $this->buildRole($org->id, 'crm', 'Front Desk');

        $this->postJson('/api/v1/crm/team', [
            'name' => 'Rosa Dela Cruz', 'email' => 'rosa-label@client.ph',
            'role' => "custom:{$roleId}", 'password' => 'RosaDC2026!',
        ])->assertCreated();

        $this->postJson('/api/v1/crm/team', [
            'name' => 'Preset Pete', 'email' => 'pete-label@client.ph',
            'role' => 'crm_bd', 'password' => 'PetePP2026!',
        ])->assertCreated();

        // Assignment is recorded on the pivot, so every module that offers custom
        // roles has to read it back -- otherwise the row names a preset the member
        // does not hold, and nobody can see who is in the role they just built.
        foreach (['/api/v1/crm/team', '/api/v1/hr/team', '/api/v1/projects/team'] as $endpoint) {
            $members = collect($this->getJson($endpoint)->assertOk()->json('data.members'))
                ->keyBy('email');

            $this->assertSame("custom:{$roleId}", $members['rosa-label@client.ph']['role'], $endpoint);
            $this->assertSame('Front Desk', $members['rosa-label@client.ph']['role_label'], $endpoint);

            // A preset holder carries no label, so the screen falls back to the preset name.
            $this->assertNull($members['pete-label@client.ph']['role_label'], $endpoint);
        }
    }

    public function test_a_custom_role_holder_can_carry_the_leads_their_role_allows(): void
    {
        [$org, $admin] = $this->clientOn(['crm' => 'full'], 'carry@client.ph');
        Sanctum::actingAs($admin);

        // A role with lead capabilities, and somebody in it.
        $caps = CurrentOrg::runAs($org->id, fn (): array => array_values(array_filter(
            array_column(CapabilityRegistry::forOrg($org->id), 'key'),
            fn (string $k): bool => str_starts_with($k, 'crm.leads.'),
        )));

        $roleId = (int) $this->postJson('/api/v1/roles', ['name' => 'Front Desk', 'permissions' => $caps])
            ->assertCreated()->json('data.id');

        $this->postJson('/api/v1/crm/team', [
            'name' => 'Rosa Dela Cruz', 'email' => 'rosa-carry@client.ph',
            'role' => "custom:{$roleId}", 'password' => 'RosaDC2026!',
        ])->assertCreated();

        $rosa = User::where('email', 'rosa-carry@client.ph')->firstOrFail();

        // The rule behind every assign, import and planner request. It asked
        // "do you hold crm_bd?", which a custom-role member never does — so the
        // capability an admin had bought and ticked could not be used.
        CurrentOrg::runAs($org->id, function () use ($rosa, $admin): void {
            $this->assertTrue($rosa->hasPermissionTo('crm.leads.view'), 'guard: the role really grants leads');

            $refusal = null;
            (new MustBeBusinessDeveloper)->validate('assigned_to', $rosa->id, function (string $m) use (&$refusal): void {
                $refusal = $m;
            });
            $this->assertNull($refusal, 'Somebody whose role carries leads must be able to carry one.');

            // And the half that must NOT change: an admin is who leads are assigned
            // BY, not to. This rule is about who carries a lead.
            $adminRefusal = null;
            (new MustBeBusinessDeveloper)->validate('assigned_to', $admin->id, function (string $m) use (&$adminRefusal): void {
                $adminRefusal = $m;
            });
            $this->assertNotNull($adminRefusal);
        });
    }
}
