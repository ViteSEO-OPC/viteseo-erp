<?php

namespace Tests\Feature\Crm;

use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * `users.last_login_at` — stamped by observing Laravel's Login event.
 *
 * The column shipped with the CRM's additive users migration, whose docblock promised it would be
 * "stamped on successful login", and nothing ever wrote it: every account read null, so the Team
 * Accounts "Last Active" column and the Credentials modal's "Last sign-in" row rendered an em dash
 * for every user, always.
 *
 * The tests below pin the three things that make the fix safe rather than merely working: it fires on
 * the REAL shared login flow (not a test-only shortcut), it does not fire when authentication fails,
 * and it writes exactly one column — `updated_at` included, because a login is not an edit.
 */
class LastLoginStampTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    private function bd(): User
    {
        $user = User::factory()->create(['status' => 'active', 'password' => 'password']);
        $user->assignRole('crm_bd');

        return $user->fresh();
    }

    public function test_a_successful_login_stamps_last_login_at(): void
    {
        $user = $this->bd();
        $this->assertNull($user->last_login_at, 'precondition: never stamped');

        $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'password',
        ])->assertOk();

        $this->assertNotNull($user->fresh()->last_login_at);
    }

    /** A second sign-in moves it forward — it is "last", not "first". */
    public function test_a_later_login_updates_the_existing_stamp(): void
    {
        $user = $this->bd();
        $user->forceFill(['last_login_at' => now()->subDays(3)])->saveQuietly();
        $before = $user->fresh()->last_login_at;

        $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'password',
        ])->assertOk();

        $this->assertTrue($user->fresh()->last_login_at->greaterThan($before));
    }

    public function test_a_failed_login_leaves_the_stamp_untouched(): void
    {
        $user = $this->bd();
        $user->forceFill(['last_login_at' => now()->subDays(3)])->saveQuietly();
        $before = $user->fresh()->last_login_at;

        $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'wrong-password',
        ])->assertStatus(422);

        $this->assertEquals($before, $user->fresh()->last_login_at);
    }

    public function test_an_unknown_email_stamps_nobody(): void
    {
        $this->bd();

        $this->postJson('/api/v1/auth/login', [
            'email' => 'nobody@example.test',
            'password' => 'password',
        ])->assertStatus(422);

        $this->assertSame(0, User::query()->whereNotNull('last_login_at')->count());
    }

    /**
     * The whole point of `timestamps = false` + `saveQuietly()`: signing in is not an account edit.
     * Compares the raw row so no cast or accessor can mask a write.
     */
    public function test_login_writes_last_login_at_and_nothing_else(): void
    {
        $user = $this->bd();
        $before = (array) DB::table('users')->where('id', $user->id)->first();

        $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'password',
        ])->assertOk();

        $after = (array) DB::table('users')->where('id', $user->id)->first();

        $this->assertNotSame($before['last_login_at'], $after['last_login_at'], 'the stamp moved');
        $this->assertSame(
            $before['updated_at'],
            $after['updated_at'],
            'a login must not look like an account edit',
        );

        unset($before['last_login_at'], $after['last_login_at']);
        $this->assertSame($before, $after, 'no other users column changed');
    }

    /** Roles are untouched — the listener observes the login, it does not manage access. */
    public function test_login_does_not_touch_roles(): void
    {
        $user = $this->bd();
        $pivotBefore = DB::table('model_has_roles')->where('model_id', $user->id)->count();

        $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'password',
        ])->assertOk();

        $this->assertSame($pivotBefore, DB::table('model_has_roles')->where('model_id', $user->id)->count());
        $this->assertSame(['crm_bd'], $user->fresh()->getRoleNames()->all());
    }

    /** Explicitly NOT an audit event — the append-only trail is not a login log. */
    public function test_login_writes_no_activity_row(): void
    {
        $user = $this->bd();
        $before = DB::table('crm_activities')->count();

        $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'password',
        ])->assertOk();

        $this->assertSame($before, DB::table('crm_activities')->count());
    }

    /** The API already exposed the field; it simply had nothing to show. */
    public function test_the_team_accounts_payload_carries_the_stamp(): void
    {
        $bd = $this->bd();
        $admin = User::factory()->create(['status' => 'active']);
        $admin->assignRole('crm_admin');

        $this->postJson('/api/v1/auth/login', ['email' => $bd->email, 'password' => 'password'])->assertOk();

        $row = collect(
            $this->actingAs($admin)->getJson('/api/v1/crm/users')->assertOk()->json('data')
        )->firstWhere('id', $bd->id);

        $this->assertNotNull($row['last_login_at']);
    }
}
