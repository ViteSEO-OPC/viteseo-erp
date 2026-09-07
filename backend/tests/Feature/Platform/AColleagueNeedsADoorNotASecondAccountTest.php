<?php

declare(strict_types=1);

namespace Tests\Feature\Platform;

use App\Models\User;
use App\Services\OrgOnboardingService;
use App\Support\Seats;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Why the member list had to show people who could not use the system.
 *
 * One person holds ONE account across the whole platform, so "add" could only
 * ever mean "create". Putting an existing colleague into a second system meant
 * finding their row in that system's member list and changing it there — which
 * is why the list listed the whole org. Measured on the provider org: Zerem's
 * roster carried twenty-six rows of which twenty-two read "No Zerem access".
 *
 * The operator wants the roster to be the people who use the system. That is
 * only safe once the address has a door of its own, or hiding those rows would
 * remove the only way to grant access at all.
 *
 * The single-org invariant is what makes this safe to do by email: the lookup is
 * org-scoped, so another tenant's address is refused rather than adopted.
 */
class AColleagueNeedsADoorNotASecondAccountTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{\App\Models\Org, User} */
    private function clientOn(array $systems, string $email): array
    {
        $r = app(OrgOnboardingService::class)->createOrg(
            'Client '.$email, 'Owner', $email, 'password-123', $systems,
        );

        return [$r['org'], $r['admin']];
    }

    public function test_an_existing_colleague_is_granted_access_rather_than_refused(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        [$org, $admin] = $this->clientOn(['hr' => 'full', 'crm' => 'full'], 'owner@bituin.ph');
        Sanctum::actingAs($admin);

        $this->postJson('/api/v1/hr/team', [
            'name' => 'Rosa Dela Cruz', 'email' => 'rosa@bituin.ph',
            'role' => 'hr_employee', 'password' => 'RosaDC2026!',
        ])->assertCreated();

        $seatsBefore = Seats::used($org->id);

        // The same address, now for CRM. Before, this came back
        // "The email has already been taken." and the only way in was her row.
        $this->postJson('/api/v1/crm/team', [
            'name' => 'ignored', 'email' => 'rosa@bituin.ph', 'role' => 'crm_bd',
        ])->assertOk()->assertJsonPath('data.granted', true);

        $rosa = User::where('email', 'rosa@bituin.ph')->firstOrFail();
        $this->assertTrue($rosa->hasRole('crm_bd'));
        $this->assertTrue($rosa->hasRole('hr_employee'), 'A grant adds access; it does not replace it.');

        // She already occupies a seat, so granting must not spend a second one.
        $this->assertSame($seatsBefore, Seats::used($org->id));

        // And `name` is ignored rather than applied: this button is not a rename.
        $this->assertSame('Rosa Dela Cruz', $rosa->name);
        $this->assertSame(1, DB::table('org_user')->where('user_id', $rosa->id)->count());
    }

    public function test_another_tenants_address_is_refused_not_adopted(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        [, $adminA] = $this->clientOn(['crm' => 'full'], 'a@one.ph');
        Sanctum::actingAs($adminA);
        $this->postJson('/api/v1/crm/team', [
            'name' => 'Theirs', 'email' => 'theirs@one.ph',
            'role' => 'crm_bd', 'password' => 'Theirs2026!',
        ])->assertCreated();

        [, $adminB] = $this->clientOn(['crm' => 'full'], 'b@two.ph');
        Sanctum::actingAs($adminB);

        // The whole tenancy model rests on this: one person, one org. An address
        // from outside is refused, never quietly pulled across.
        $this->postJson('/api/v1/crm/team', [
            'name' => 'Theirs', 'email' => 'theirs@one.ph', 'role' => 'crm_bd',
        ])->assertStatus(422)->assertJsonValidationErrors('email');

        $theirs = User::where('email', 'theirs@one.ph')->firstOrFail();
        $this->assertSame(1, DB::table('org_user')->where('user_id', $theirs->id)->count());
    }

    public function test_a_brand_new_person_still_needs_a_password_and_a_seat(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        [, $admin] = $this->clientOn(['hr' => 'full'], 'owner@new.ph');
        Sanctum::actingAs($admin);

        // Relaxing `password` for the grant branch must not relax it for the branch
        // that actually creates an account.
        $this->postJson('/api/v1/hr/team', [
            'name' => 'Nobody', 'email' => 'nobody@new.ph', 'role' => 'hr_employee',
        ])->assertStatus(422)->assertJsonValidationErrors('password');

        $this->assertDatabaseMissing('users', ['email' => 'nobody@new.ph']);
    }
}
