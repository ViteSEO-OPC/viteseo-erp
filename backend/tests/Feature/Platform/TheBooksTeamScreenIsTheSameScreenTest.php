<?php

declare(strict_types=1);

namespace Tests\Feature\Platform;

use App\Models\User;
use App\Services\OrgOnboardingService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * `/api/v1/team` is Accounting's team endpoint. It lives in App\Http\Controllers
 * rather than the module, which is the only reason it survived the module's
 * removal -- and, for two days, the reason nobody noticed it was unreachable:
 * its gate is `acc_admin`, and no such role existed. An endpoint nobody can call
 * is an endpoint nobody tests, and this one had no tests at all.
 *
 * It answers the SHARED contract now (`data: { members, seats }`), because the
 * page that calls it is the shared TeamAccountsManager -- the same component
 * TeamOra, Zerem and Sprintly mount. The manager reads one envelope and splits
 * it: members with a role are the roster, members with `role: null` are the
 * "others in this organisation" count. Answering the old shape would not throw;
 * it would render an empty roster next to a seat count, which is the failure
 * this file exists to catch.
 */
class TheBooksTeamScreenIsTheSameScreenTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_roster_answers_the_shape_the_shared_screen_reads(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        $result = app(OrgOnboardingService::class)->createOrg(
            'Bituin Books', 'Maria', 'maria@bituin.ph', 'password-123', ['accounting' => 'full'],
        );

        Sanctum::actingAs($result['admin']);

        $this->getJson('/api/v1/team')
            ->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'members' => [['id', 'name', 'email', 'role', 'role_label', 'is_you']],
                    'seats' => ['used', 'limit'],
                ],
            ])
            // The owner is on the roster holding the preset onboarding gave them,
            // not a null the screen would file under "others".
            ->assertJsonPath('data.members.0.role', 'acc_admin')
            ->assertJsonPath('data.members.0.is_you', true);
    }

    /**
     * Someone in the org who holds nothing in Accounting comes back with a NULL
     * role rather than being dropped. The manager needs both halves: the roster
     * is who works in the books, and the count under it is proof the others
     * exist -- so an admin can tell "nobody else is here" from "everybody else
     * is somewhere else".
     */
    public function test_a_colleague_from_another_system_is_listed_with_no_role(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        $result = app(OrgOnboardingService::class)->createOrg(
            'Buo Agency', 'Dino', 'dino@buo.ph', 'password-123', ['hr' => 'full', 'accounting' => 'full'],
        );

        Sanctum::actingAs($result['admin']);

        // A colleague hired into HR, through the door HR's own screen uses.
        $this->postJson('/api/v1/hr/team', [
            'name' => 'Zoe Reyes', 'email' => 'zoe@buo.ph',
            'role' => 'hr_officer', 'password' => 'ZoeR2026!',
        ])->assertCreated();

        $response = $this->getJson('/api/v1/team')->assertOk();

        $members = collect($response->json('data.members'));
        $zoe = $members->firstWhere('email', 'zoe@buo.ph');

        $this->assertNotNull($zoe, 'the colleague is listed, not filtered away');
        $this->assertNull($zoe['role'], 'and holds nothing in the books');
    }

    /** The presets picker offers Accounting's three roles -- and only to an org that bought it. */
    public function test_the_role_builder_offers_the_accounting_presets(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        $books = app(OrgOnboardingService::class)->createOrg(
            'Libro Lang', 'Cely', 'cely@libro.ph', 'password-123', ['accounting' => 'full'],
        );

        Sanctum::actingAs($books['admin']);
        $presets = collect($this->getJson('/api/v1/roles/presets')->assertOk()->json('data'));

        $this->assertEqualsCanonicalizing(
            ['acc_admin', 'acc_accountant', 'acc_viewer'],
            $presets->pluck('key')->all(),
            'exactly the three accounting presets, nothing from a system this org did not buy',
        );

        // Each carries the capabilities its role actually holds, so the builder can
        // seed a custom role from one.
        $admin = $presets->firstWhere('key', 'acc_admin');
        $this->assertContains(
            'accounting.journal.reverse',
            collect($admin['capabilities'])->pluck('key')->all(),
        );
    }

    public function test_a_non_admin_cannot_read_the_roster(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        $result = app(OrgOnboardingService::class)->createOrg(
            'Bituin Books', 'Maria', 'maria@bituin.ph', 'password-123', ['accounting' => 'full'],
        );

        Sanctum::actingAs($result['admin']);
        $this->postJson('/api/v1/team', [
            'name' => 'Ben Santos', 'email' => 'book@bituin.ph',
            'role' => 'acc_accountant', 'password' => 'BenS2026!',
        ])->assertCreated();

        // The bookkeeper keeps the books; who else may open them is not theirs.
        Sanctum::actingAs(User::where('email', 'book@bituin.ph')->firstOrFail());
        $this->getJson('/api/v1/team')->assertForbidden();
    }
}
