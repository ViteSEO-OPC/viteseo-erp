<?php

declare(strict_types=1);

namespace Tests\Feature\Platform;

use App\Events\AccessChanged;
use App\Models\User;
use App\Modules\Crm\Services\CrmAccessService;
use App\Services\OrgOnboardingService;
use App\Support\CurrentOrg;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/**
 * When access is taken away, the session that is already open finds out.
 *
 * Refusing the next request is not enough on its own. A person whose access was
 * removed still has the page open, and until they click something it goes on
 * showing what they may no longer see. So every change to what someone may use
 * is announced on their private `user.{id}` channel, and each of their open
 * browsers re-reads who they are at once.
 *
 * What that re-read finds depends on what is left. Lose one system of several --
 * the CRM, say, while keeping HR -- and the browser is walked out of the CRM and
 * stays signed in. Lose everything -- leave the company, lose your only system,
 * be suspended from the only one you had -- and every session and token you hold
 * is ended on the server in the same moment, so there is nothing to walk back to.
 */
class TakenAccessLeavesTheOpenSessionTooTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'password-123';

    protected function setUp(): void
    {
        parent::setUp();

        config(['session.driver' => 'database']);
        Event::fake([AccessChanged::class]);
        // Access is judged once a request, command or job is done (AccessLifecycle::
        // schedule); a test body is none of those, so judge it on the spot.
        $this->withoutDefer();

        $this->seed(RolesAndPermissionsSeeder::class);
        app(OrgOnboardingService::class)->createOrg(
            'Bituin Dental', 'Maria', 'maria@bituin.ph', self::PASSWORD, ['hr' => 'full', 'crm' => 'full'],
        );
    }

    private function person(string $email, array $roles): User
    {
        $user = User::factory()->create(['email' => $email, 'password' => self::PASSWORD, 'status' => 'active']);
        $user->assignRole($roles);
        Event::fake([AccessChanged::class]); // granting them their roles is the fixture, not the test

        return $user;
    }

    /** The next request from one browser; see EverySignedInDeviceIsListedAndCanBeEndedTest::on(). */
    private function browser(?string $session): static
    {
        $this->flushHeaders();
        $this->app['auth']->forgetGuards();
        $this->app->forgetInstance('auth.driver');
        $this->app['session']->driver()->flush();
        CurrentOrg::clear();
        $this->defaultCookies = [];

        if ($session !== null) {
            $this->withCookie(config('session.cookie'), $session);
        }

        return $this->withCredentials()->fromTheApp();
    }

    private function signIn(User $user): string
    {
        return $this->browser(null)
            ->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => self::PASSWORD])
            ->assertOk()
            ->getCookie(config('session.cookie'))
            ->getValue();
    }

    private function assertSignedOutEverywhere(User $user, string ...$sessions): void
    {
        $this->assertSame(0, DB::table('sessions')->where('user_id', $user->id)->count(),
            'Every session they held must be gone from the server, not merely refused.');
        foreach ($sessions as $session) {
            $this->browser($session)->getJson('/api/v1/auth/me')->assertUnauthorized();
        }
        Event::assertDispatched(AccessChanged::class,
            fn (AccessChanged $e): bool => $e->userId === $user->id && $e->signedOut);
    }

    private function assertStillSignedIn(User $user, string $session): void
    {
        $this->browser($session)->getJson('/api/v1/auth/me')->assertOk();
        Event::assertDispatched(AccessChanged::class,
            fn (AccessChanged $e): bool => $e->userId === $user->id && ! $e->signedOut);
    }

    public function test_someone_who_leaves_is_signed_out_of_every_device_at_once(): void
    {
        $ana = $this->person('ana@bituin.ph', ['hr_employee', 'crm_bd']);
        $laptop = $this->signIn($ana);
        $phone = $this->signIn($ana);

        // What both HR's separation and hr:apply-separations do to the account.
        $ana->forceFill(['status' => User::STATUS_INACTIVE])->save();

        $this->assertSignedOutEverywhere($ana, $laptop, $phone);
    }

    public function test_losing_the_only_system_you_had_signs_you_out(): void
    {
        $ana = $this->person('ana@bituin.ph', ['crm_bd']);
        $session = $this->signIn($ana);

        $ana->removeRole('crm_bd');

        $this->assertSignedOutEverywhere($ana, $session);
    }

    public function test_losing_one_system_of_several_keeps_you_signed_in(): void
    {
        $ben = $this->person('ben@bituin.ph', ['hr_employee', 'crm_bd']);
        $session = $this->signIn($ben);

        $ben->removeRole('crm_bd');

        // Still signed in -- and told, so the open CRM page walks them out of it.
        $this->assertStillSignedIn($ben, $session);
    }

    public function test_a_crm_suspension_signs_out_someone_who_had_only_the_crm(): void
    {
        $ana = $this->person('ana@bituin.ph', ['crm_bd']);
        $session = $this->signIn($ana);

        app(CrmAccessService::class)->suspend($ana);

        $this->assertSignedOutEverywhere($ana, $session);
    }

    public function test_a_crm_suspension_leaves_the_rest_of_their_access_open(): void
    {
        $ben = $this->person('ben@bituin.ph', ['hr_employee', 'crm_bd']);
        $session = $this->signIn($ben);

        app(CrmAccessService::class)->suspend($ben);

        $this->assertStillSignedIn($ben, $session);
    }

    public function test_being_given_a_system_tells_the_open_session_too(): void
    {
        $ben = $this->person('ben@bituin.ph', ['hr_employee']);
        $session = $this->signIn($ben);

        $ben->assignRole('pm_member');

        // So the new system appears without signing out and back in.
        $this->assertStillSignedIn($ben, $session);
    }

    public function test_changing_the_password_tells_the_other_devices_at_once(): void
    {
        $maria = User::where('email', 'maria@bituin.ph')->firstOrFail();
        $laptop = $this->signIn($maria);
        $this->signIn($maria);

        $this->browser($laptop)->postJson('/api/v1/auth/change-password', [
            'current_password' => self::PASSWORD,
            'password' => 'a-new-one-2026',
            'password_confirmation' => 'a-new-one-2026',
        ])->assertOk();

        Event::assertDispatched(AccessChanged::class,
            fn (AccessChanged $e): bool => $e->userId === $maria->id && $e->reason === 'password-changed');
    }

    public function test_ending_a_device_from_the_list_tells_that_device_at_once(): void
    {
        $maria = User::where('email', 'maria@bituin.ph')->firstOrFail();
        $laptop = $this->signIn($maria);
        $this->signIn($maria);

        $this->browser($laptop)->deleteJson('/api/v1/auth/sessions')->assertOk();

        // Its next request would be refused anyway; the broadcast makes it now.
        Event::assertDispatched(AccessChanged::class,
            fn (AccessChanged $e): bool => $e->userId === $maria->id && $e->reason === 'session-ended');
    }
}
