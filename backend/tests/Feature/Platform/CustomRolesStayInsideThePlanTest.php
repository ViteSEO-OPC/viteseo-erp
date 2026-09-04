<?php

declare(strict_types=1);

namespace Tests\Feature\Platform;

use App\Models\Subscription;
use App\Services\OrgOnboardingService;
use App\Support\CapabilityRegistry;
use App\Support\CurrentOrg;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * A client builds its own roles, and the plan is the fence.
 *
 * The whole point of "the org's super admin can create roles and put
 * functionalities on them" is that the functionalities on offer are the ones that
 * org PAID FOR. Before 2026-09-03 the builder offered a hardcoded list of twelve
 * accounting permissions to everyone, which meant a CRM customer saw nothing they
 * could use and an accounting customer on the cheapest tier saw everything.
 *
 * These tests hold down the two halves of the fence, because they fail
 * differently: the PICKER shapes what an honest admin can click, and the WRITER
 * refuses what a request body can name regardless of what the picker showed. A
 * plan gate that lives only in the picker is a plan gate that anyone with curl
 * can walk around.
 */
class CustomRolesStayInsideThePlanTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{\App\Models\Org, \App\Models\User} */
    private function orgOn(array $systems, string $email): array
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        $result = app(OrgOnboardingService::class)->createOrg(
            'Org '.$email, 'Owner', $email, 'password-123', $systems,
        );

        return [$result['org'], $result['admin']];
    }

    public function test_the_picker_offers_only_what_the_org_availed(): void
    {
        [$org, $admin] = $this->orgOn(['crm' => 'basic'], 'crm-basic@client.ph');
        Sanctum::actingAs($admin);

        $features = collect($this->getJson('/api/v1/capabilities')->assertOk()->json('data'))
            ->pluck('feature');

        // CRM Basic bundles the core and the performance pack, and nothing else.
        $this->assertContains('crm', $features);
        $this->assertContains('crm.performance', $features);

        // Outreach is sold on Pro. It is not on offer here.
        $this->assertNotContains('crm.outreach', $features);

        // And an org that never availed HRIS must not be able to grant a single
        // HR capability -- not a reduced set, none.
        $this->assertNotContains('hr', $features);
        $this->assertNotContains('hr.payroll', $features);
    }

    public function test_a_capability_outside_the_plan_is_refused_on_write(): void
    {
        [$org, $admin] = $this->orgOn(['crm' => 'basic'], 'writer@client.ph');
        Sanctum::actingAs($admin);

        $outside = CurrentOrg::runAs($org->id, function (): string {
            $all = collect(CapabilityRegistry::all())->firstWhere('feature', 'crm.outreach');

            return $all['key'];
        });

        // The picker never showed it. The request names it anyway.
        $this->postJson('/api/v1/roles', [
            'name' => 'Sneaky Outreach',
            'permissions' => [$outside],
        ])->assertStatus(422)->assertJsonValidationErrors('permissions');
    }

    public function test_a_capability_inside_the_plan_is_accepted(): void
    {
        [$org, $admin] = $this->orgOn(['crm' => 'basic'], 'inside@client.ph');
        Sanctum::actingAs($admin);

        $inside = CurrentOrg::runAs($org->id, function (): string {
            return collect(CapabilityRegistry::forOrg(CurrentOrg::id()))
                ->firstWhere('feature', 'crm')['key'];
        });

        $this->postJson('/api/v1/roles', [
            'name' => 'Lead Reader',
            'permissions' => [$inside],
        ])->assertCreated()->assertJsonPath('data.name', 'Lead Reader');
    }

    public function test_a_bigger_plan_unlocks_more_of_the_same_catalog(): void
    {
        [, $basic] = $this->orgOn(['crm' => 'basic'], 'ladder-basic@client.ph');
        Sanctum::actingAs($basic);
        $small = collect($this->getJson('/api/v1/capabilities')->json('data'))->pluck('feature');

        [, $full] = $this->orgOn(['crm' => 'full'], 'ladder-full@client.ph');
        Sanctum::actingAs($full);
        $big = collect($this->getJson('/api/v1/capabilities')->json('data'))->pluck('feature');

        // Full is a superset, not a different catalogue: the ladder has to be a
        // ladder, or "upgrade to get X" is not a true sentence.
        foreach ($small as $feature) {
            $this->assertContains($feature, $big, "Full lost {$feature}, which Basic had");
        }
        $this->assertContains('crm.outreach', $big);
        $this->assertNotContains('crm.outreach', $small);
    }

    public function test_an_org_that_availed_two_systems_sees_both(): void
    {
        [, $admin] = $this->orgOn(['crm' => 'basic', 'hr' => 'basic'], 'two@client.ph');
        Sanctum::actingAs($admin);

        $features = collect($this->getJson('/api/v1/capabilities')->json('data'))->pluck('feature');

        $this->assertContains('crm', $features);
        $this->assertContains('hr', $features);
    }

    public function test_a_lapsed_subscription_is_not_a_licence_to_build_roles(): void
    {
        [$org, $admin] = $this->orgOn(['crm' => 'basic'], 'lapsed@client.ph');
        Subscription::query()->where('org_id', $org->id)->update(['status' => Subscription::SUSPENDED]);

        Sanctum::actingAs($admin);

        // Whatever the answer is, it must not be "here is the whole catalogue".
        $response = $this->getJson('/api/v1/capabilities');
        if ($response->status() === 200) {
            $features = collect($response->json('data'))->pluck('feature');
            $this->assertNotContains('hr', $features);
            $this->assertNotContains('crm.outreach', $features);
        } else {
            $this->assertContains($response->status(), [402, 403]);
        }
    }

    /**
     * The presets an org is shown are the presets it bought.
     *
     * This endpoint used to describe three ACCOUNTING roles that do not exist in this
     * deployment, so every client was shown three empty names and learned nothing
     * about what a preset actually grants.
     */
    public function test_the_presets_shown_are_the_ones_the_org_availed(): void
    {
        [, $admin] = $this->orgOn(['crm' => 'basic'], 'presets@client.ph');
        Sanctum::actingAs($admin);

        $data = collect($this->getJson('/api/v1/roles/presets')->assertOk()->json('data'));

        $this->assertSame(['crm_admin', 'crm_bd'], $data->pluck('key')->sort()->values()->all());
        // And they say what they grant, rather than coming back empty.
        $this->assertNotEmpty($data->firstWhere('key', 'crm_admin')['capabilities']);
        $this->assertEmpty($data->where('feature', 'hr'), 'HR presets leaked to a CRM-only org.');
    }

    /**
     * Deleting a role that somebody holds must not 500, and must not demote them.
     *
     * It used to `syncRoles(['acc_viewer'])` -- a role absent from this deployment, so
     * Spatie threw and the request answered 500. On a deployment where it DID exist,
     * syncRoles replaces the member's roles wholesale, so an HR officer would have
     * quietly lost hr_officer along with the custom capabilities.
     */
    public function test_deleting_a_role_removes_its_capabilities_and_leaves_the_preset(): void
    {
        [$org, $admin] = $this->orgOn(['crm' => 'basic'], 'delete@client.ph');
        Sanctum::actingAs($admin);

        $inside = CurrentOrg::runAs($org->id, fn (): string => collect(CapabilityRegistry::forOrg(CurrentOrg::id()))
            ->firstWhere('feature', 'crm')['key']);

        $id = $this->postJson('/api/v1/roles', ['name' => 'Reader', 'permissions' => [$inside]])
            ->assertCreated()->json('data.id');

        // The admin holds it, and keeps holding crm_admin.
        \DB::table('org_user')->where('user_id', $admin->id)->update(['custom_role_id' => $id]);
        $admin->syncPermissions([$inside]);

        $this->deleteJson("/api/v1/roles/{$id}")->assertOk();

        $admin->refresh();
        $this->assertTrue($admin->hasRole('crm_admin'), 'The preset was stripped along with the custom role.');
        $this->assertEmpty($admin->getDirectPermissions(), 'The custom capabilities survived the delete.');
    }
}