<?php

declare(strict_types=1);

namespace Tests\Feature\Platform;

use App\Models\User;
use App\Services\OrgOnboardingService;
use App\Support\CurrentOrg;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * You can see every device you are signed in on, and end any of them.
 *
 * Signing in on a phone no longer signs the laptop out -- that would kill an HR
 * officer's payroll screen the moment they checked something on their phone.
 * Instead each browser gets its own session, and the person can see them all
 * and close the one they do not recognise: the stolen laptop, the shared PC they
 * forgot to sign out of.
 *
 * The list is built from the `sessions` table, which already records the user,
 * address, browser and last activity of every session. What it must never do is
 * hand the session id to the page: that id IS the cookie's content, and showing
 * it to script would undo the reason the session moved into an httpOnly cookie.
 * Each row is named by a hash instead.
 */
class EverySignedInDeviceIsListedAndCanBeEndedTest extends TestCase
{
    use RefreshDatabase;

    private const EMAIL = 'maria@bituin.ph';

    private const PASSWORD = 'password-123';

    private const DEVICES = [
        'laptop' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/139.0.0.0 Safari/537.36',
        'phone' => 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_5 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.5 Mobile/15E148 Safari/604.1',
        'tablet' => 'Mozilla/5.0 (Linux; Android 14; SM-X710) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/139.0.0.0 Safari/537.36',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        // The list reads the sessions table, so this suite keeps its sessions there
        // -- as production does -- instead of in phpunit's in-memory array.
        config(['session.driver' => 'database']);

        $this->seed(RolesAndPermissionsSeeder::class);
        app(OrgOnboardingService::class)->createOrg(
            'Bituin Dental', 'Maria', self::EMAIL, self::PASSWORD, ['hr' => 'full'],
        );
    }

    /**
     * The next request, from one particular browser: its own User-Agent, its own
     * cookie, and nothing the previous request left behind in this process. See
     * ASignedInBrowserHoldsNoTokenTest::asTheBrowser() for why each reset matters.
     */
    private function on(string $device, ?string $session): static
    {
        $this->flushHeaders();
        $this->app['auth']->forgetGuards();
        // The session driver asks Guard::class whose session it is writing, and that
        // is the `auth.driver` SINGLETON -- which forgetGuards() does not reset. Left
        // alone it keeps answering with the first person to sign in, and a second
        // person's session would be written under the first person's user_id.
        $this->app->forgetInstance('auth.driver');
        $this->app['session']->driver()->flush();
        CurrentOrg::clear();
        $this->defaultCookies = [];

        if ($session !== null) {
            $this->withCookie(config('session.cookie'), $session);
        }

        return $this->withCredentials()->fromTheApp()->withHeader('User-Agent', self::DEVICES[$device]);
    }

    private function signInOn(string $device, string $email = self::EMAIL): string
    {
        $response = $this->on($device, null)->postJson('/api/v1/auth/login', [
            'email' => $email,
            'password' => self::PASSWORD,
        ])->assertOk();

        $cookie = $response->getCookie(config('session.cookie'));
        $this->assertNotNull($cookie, 'Signing in set no session cookie.');

        return $cookie->getValue();
    }

    /** @return list<array<string, mixed>> */
    private function sessionsSeenFrom(string $device, string $session): array
    {
        return $this->on($device, $session)->getJson('/api/v1/auth/sessions')->assertOk()->json('data');
    }

    public function test_every_device_you_are_signed_in_on_is_listed(): void
    {
        $laptop = $this->signInOn('laptop');
        $this->signInOn('phone');

        $rows = collect($this->sessionsSeenFrom('laptop', $laptop));

        $this->assertCount(2, $rows);
        $this->assertSame(1, $rows->where('current', true)->count(), 'Exactly one row is the device asking.');
        $this->assertSame('Chrome on Windows', $rows->firstWhere('current', true)['device']);
        $this->assertSame('Safari on iPhone', $rows->firstWhere('current', false)['device']);
        $this->assertNotEmpty($rows->first()['last_active_at']);
    }

    public function test_the_list_never_hands_a_session_id_to_the_page(): void
    {
        $laptop = $this->signInOn('laptop');
        $phone = $this->signInOn('phone');

        $body = $this->on('laptop', $laptop)->getJson('/api/v1/auth/sessions')->assertOk()->getContent();

        $this->assertStringNotContainsString($laptop, $body, 'This device\'s own session id is its credential.');
        $this->assertStringNotContainsString($phone, $body, 'Another device\'s session id would let this page become it.');
    }

    public function test_ending_another_device_signs_it_out_there_and_nowhere_else(): void
    {
        $laptop = $this->signInOn('laptop');
        $phone = $this->signInOn('phone');

        $phoneKey = collect($this->sessionsSeenFrom('laptop', $laptop))->firstWhere('current', false)['key'];

        $this->on('laptop', $laptop)->deleteJson("/api/v1/auth/sessions/{$phoneKey}")->assertOk();

        $this->on('phone', $phone)->getJson('/api/v1/auth/me')->assertUnauthorized();
        $this->on('laptop', $laptop)->getJson('/api/v1/auth/me')->assertOk();
    }

    public function test_signing_out_everywhere_else_keeps_only_this_device(): void
    {
        $laptop = $this->signInOn('laptop');
        $phone = $this->signInOn('phone');
        $tablet = $this->signInOn('tablet');

        $this->on('laptop', $laptop)->deleteJson('/api/v1/auth/sessions')->assertOk();

        $this->on('phone', $phone)->getJson('/api/v1/auth/me')->assertUnauthorized();
        $this->on('tablet', $tablet)->getJson('/api/v1/auth/me')->assertUnauthorized();
        $this->assertCount(1, $this->sessionsSeenFrom('laptop', $laptop));
    }

    public function test_you_cannot_end_a_session_that_is_not_yours(): void
    {
        User::factory()->create(['email' => 'jose@bituin.ph', 'password' => self::PASSWORD, 'status' => 'active']);

        $maria = $this->signInOn('laptop');
        $jose = $this->signInOn('phone', 'jose@bituin.ph');
        $josesKey = collect($this->sessionsSeenFrom('phone', $jose))->firstWhere('current', true)['key'];

        // Knowing the key is not enough: it has to name a session of your own.
        $this->on('laptop', $maria)->deleteJson("/api/v1/auth/sessions/{$josesKey}")->assertNotFound();

        $this->on('phone', $jose)->getJson('/api/v1/auth/me')->assertOk();
    }

    public function test_a_session_that_has_gone_idle_is_not_listed(): void
    {
        $this->signInOn('phone');
        $this->travel(2 * 24 * 60 + 1)->minutes();
        $laptop = $this->signInOn('laptop');

        // The phone's row may linger until garbage collection, but it opens
        // nothing any more, so it is not something to show or to worry about.
        $this->assertCount(1, $this->sessionsSeenFrom('laptop', $laptop));
    }
}
