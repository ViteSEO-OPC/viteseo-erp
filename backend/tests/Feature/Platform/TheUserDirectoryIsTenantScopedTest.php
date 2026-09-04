<?php

declare(strict_types=1);

namespace Tests\Feature\Platform;

use App\Models\Org;
use App\Models\User;
use App\Services\OrgOnboardingService;
use App\Support\CurrentOrg;
use App\Support\OrgUserRule;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * `users` is the one table tenancy deliberately did NOT partition.
 *
 * An account is platform-wide — one login, many orgs, membership in `org_user` —
 * so `users` has no org_id and the BelongsToOrg global scope cannot reach it. That
 * is a correct design decision with one consequence nobody re-checked when tenancy
 * landed: every gate that used to mean "is allowed to see the staff directory" now
 * means "is allowed to see EVERY tenant's staff directory". The Projects picker was
 * gated on `projects.projects.view`, which was exactly right before there was more
 * than one tenant.
 *
 * The rule half matters just as much as the listing half. A picker that hides
 * another org's users is cosmetic if the endpoint behind it still accepts their id
 * from a hand-written request — the leak then runs the other way, planting a
 * foreign account as an assignee, a group leader or a lead owner.
 */
class TheUserDirectoryIsTenantScopedTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{Org, User} */
    private function orgOnProjects(string $email): array
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        $result = app(OrgOnboardingService::class)->createOrg(
            'Org '.$email, 'Owner '.$email, $email, 'password-123', ['pms' => 'basic'],
        );

        return [$result['org'], $result['admin']];
    }

    public function test_the_projects_directory_lists_only_the_callers_own_org(): void
    {
        [, $ours] = $this->orgOnProjects('alpha@client.ph');
        [, $theirs] = $this->orgOnProjects('beta@client.ph');

        Sanctum::actingAs($ours);

        $emails = collect($this->getJson('/api/v1/projects/users')->assertOk()->json('data'))
            ->pluck('email');

        $this->assertContains($ours->email, $emails);

        // The endpoint returns names AND email addresses. This is the whole staff
        // list of another paying customer.
        $this->assertNotContains($theirs->email, $emails, "Alpha read Beta's staff directory.");
    }

    public function test_a_search_cannot_reach_across_the_fence_either(): void
    {
        [, $ours] = $this->orgOnProjects('alpha2@client.ph');
        [, $theirs] = $this->orgOnProjects('beta2@client.ph');

        Sanctum::actingAs($ours);

        // Searching by the other tenant's exact address — the shape that turns a
        // listing into a lookup ("does this person work at that company?").
        $emails = collect(
            $this->getJson('/api/v1/projects/users?search='.urlencode($theirs->email))
                ->assertOk()->json('data')
        )->pluck('email');

        $this->assertNotContains($theirs->email, $emails);
    }

    public function test_a_user_id_from_another_org_fails_validation(): void
    {
        [$ourOrg, $ours] = $this->orgOnProjects('alpha3@client.ph');
        [, $theirs] = $this->orgOnProjects('beta3@client.ph');

        CurrentOrg::runAs($ourOrg->id, function () use ($ours, $theirs): void {
            $rule = ['user_id' => ['required', 'integer', OrgUserRule::exists()]];

            $this->assertTrue(
                Validator::make(['user_id' => $ours->id], $rule)->passes(),
                'The rule rejected a member of the current org.',
            );

            // `exists:users,id` accepted this. It is a real row in a real table —
            // just not this tenant's.
            $this->assertFalse(
                Validator::make(['user_id' => $theirs->id], $rule)->passes(),
                "Another tenant's user id passed validation.",
            );
        });
    }

    public function test_an_orgless_account_is_not_a_member_of_anything(): void
    {
        [$ourOrg] = $this->orgOnProjects('alpha4@client.ph');

        // No org_user row at all — a platform account that belongs to no tenant.
        $stray = User::create([
            'name' => 'Stray',
            'email' => 'stray@nowhere.test',
            'password' => 'password-123',
        ]);

        CurrentOrg::runAs($ourOrg->id, function () use ($stray): void {
            $this->assertFalse(
                Validator::make(
                    ['user_id' => $stray->id],
                    ['user_id' => ['required', 'integer', OrgUserRule::exists()]],
                )->passes(),
            );
        });
    }

    public function test_the_directory_is_the_people_who_can_use_sprintly(): void
    {
        [$org, $admin] = $this->orgOnProjects('owner@bituin.ph');
        Sanctum::actingAs($admin);

        // Someone in HR and nothing else. A real shape: most of an org is not on
        // the project tool.
        $this->postJson('/api/v1/projects/team', [
            'name' => 'Pia Cruz', 'email' => 'pia@bituin.ph',
            'role' => 'pm_member', 'password' => 'PiaC2026!',
        ])->assertCreated();

        $hrOnly = User::factory()->create(['name' => 'Nora HR', 'email' => 'nora@bituin.ph']);
        $hrOnly->assignRole('hr_employee');
        // One org per person is the keystone the whole model rests on, and the test
        // harness has already placed this account somewhere. Move her rather than
        // adding a second membership, which the unique index would refuse anyway.
        DB::table('org_user')->where('user_id', $hrOnly->id)->delete();
        $org->users()->attach($hrOnly->id, ['is_default' => true]);

        $emails = collect($this->getJson('/api/v1/projects/users?per_page=100')->assertOk()->json('data'))
            ->pluck('email');

        $this->assertContains('pia@bituin.ph', $emails->all());

        // Not merely somebody else's name in a picker: before this, work could be
        // assigned to a person who cannot open the system to see it.
        $this->assertNotContains('nora@bituin.ph', $emails->all());
    }

    public function test_a_member_on_one_of_the_orgs_own_roles_is_still_in_the_directory(): void
    {
        [$org, $admin] = $this->orgOnProjects('owner@custom.ph');
        Sanctum::actingAs($admin);

        $caps = CurrentOrg::runAs($org->id, fn (): array => array_slice(array_column(
            array_filter(\App\Support\CapabilityRegistry::forOrg($org->id), fn (array $c): bool => $c['feature'] === 'pms'),
            'key',
        ), 0, 2));

        $roleId = (int) $this->postJson('/api/v1/roles', ['name' => 'Reviewer', 'permissions' => $caps])
            ->assertCreated()->json('data.id');

        $this->postJson('/api/v1/projects/team', [
            'name' => 'Rosa Dela Cruz', 'email' => 'rosa@custom.ph',
            'role' => "custom:{$roleId}", 'password' => 'RosaDC2026!',
        ])->assertCreated();

        // The trap in narrowing this list: a custom-role member holds NO Spatie role,
        // so a filter written as "pm_apm or pm_member" would drop them from the very
        // pickers the capabilities were bought for.
        $emails = collect($this->getJson('/api/v1/projects/users?per_page=100')->assertOk()->json('data'))
            ->pluck('email');

        $this->assertContains('rosa@custom.ph', $emails->all());
    }
}
