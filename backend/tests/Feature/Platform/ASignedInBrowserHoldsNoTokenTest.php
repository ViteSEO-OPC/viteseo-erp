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
 * The browser signs in with a cookie no script on the page can read.
 *
 * Until now a successful login answered with a Sanctum token in the JSON body,
 * and the frontend kept it in localStorage. localStorage is readable by every
 * script the page runs -- a compromised npm dependency, an injected snippet --
 * and by anyone who presses F12. Whoever read it could carry the account to
 * another machine, where it stayed good for 30 days whether anyone used it or not.
 *
 * nginx serves both halves from one origin (/ to Next, /api to Laravel), so the
 * httpOnly session cookie Laravel already knows how to issue needs no cross-site
 * arrangement. auth:sanctum accepts either credential, so the one client that
 * genuinely needs a token -- n8n, minted by `crm:n8n-token` -- keeps it.
 *
 * Moving to sessions changes what "expires" means, so the timeouts are pinned
 * here too: two days of inactivity ends a session, and thirty days ends it no
 * matter how busy -- the ceiling tokens had, which sessions do not have by default.
 *
 * Before the change these fail: the login body carries `token` and sets no cookie.
 */
class ASignedInBrowserHoldsNoTokenTest extends TestCase
{
    use RefreshDatabase;

    private const EMAIL = 'maria@bituin.ph';

    private const PASSWORD = 'password-123';

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        app(OrgOnboardingService::class)->createOrg(
            'Bituin Dental', 'Maria', self::EMAIL, self::PASSWORD, ['hr' => 'full'],
        );
    }

    private function signIn(): TestResponse
    {
        return $this->asTheBrowser(null)->postJson('/api/v1/auth/login', [
            'email' => self::EMAIL,
            'password' => self::PASSWORD,
        ]);
    }

    /**
     * What a browser does on its next request: carry the cookie it was handed, and
     * nothing else it learned.
     *
     * A PHP-FPM worker starts each request from nothing. A test does not: the guard
     * is a singleton that still remembers who signed in, and the session store is
     * one object whose load MERGES what it reads into what it already holds. Left
     * alone, both would carry the login to the next request by themselves, and every
     * "the cookie is enough" test here would pass with no cookie at all. The
     * stranger test below is the control that proves this reset works.
     */
    private function asTheBrowser(?string $session): static
    {
        $this->app['auth']->forgetGuards();
        // Guard::class is the `auth.driver` singleton, which forgetGuards() leaves
        // standing; the session driver asks it whose session it is writing.
        $this->app->forgetInstance('auth.driver');
        $this->app['session']->driver()->flush();
        CurrentOrg::clear();
        $this->defaultCookies = [];

        if ($session !== null) {
            $this->withCookie(config('session.cookie'), $session);
        }

        // getJson()/postJson() send cookies only when asked to -- a browser always does.
        return $this->withCredentials()->fromTheApp();
    }

    private function sessionFrom(TestResponse $response): string
    {
        $cookie = $response->getCookie(config('session.cookie'));
        $this->assertNotNull($cookie, 'The response set no session cookie.');

        return $cookie->getValue();
    }

    private function maria(): User
    {
        return User::where('email', self::EMAIL)->firstOrFail();
    }

    // ── The token is gone from the page ────────────────────────────────────

    public function test_signing_in_hands_javascript_no_token(): void
    {
        $response = $this->signIn()->assertOk();

        $this->assertArrayNotHasKey('token', $response->json('data'),
            'Anything in the response body is readable by the script that asked for it.');
        $this->assertSame(self::EMAIL, $response->json('data.user.email'));
        $this->assertSame(0, $this->maria()->tokens()->count(),
            'A browser sign-in must not mint a token row at all.');
    }

    public function test_the_session_cookie_is_out_of_reach_of_page_scripts(): void
    {
        $cookie = $this->signIn()->assertOk()->getCookie(config('session.cookie'), false);

        $this->assertNotNull($cookie, 'Signing in must set the session cookie.');
        $this->assertTrue($cookie->isHttpOnly(), 'httpOnly is what hides it from document.cookie and from F12 scripts.');
        $this->assertSame('lax', strtolower((string) $cookie->getSameSite()));
    }

    public function test_the_cookie_alone_carries_the_next_request(): void
    {
        $session = $this->sessionFrom($this->signIn()->assertOk());

        $this->asTheBrowser($session)->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('data.user.email', self::EMAIL);
    }

    public function test_without_the_cookie_the_next_request_is_a_stranger(): void
    {
        $this->signIn()->assertOk();

        // The control for the test above: same process, same login, no cookie.
        $this->asTheBrowser(null)->getJson('/api/v1/auth/me')->assertUnauthorized();
    }

    public function test_signing_out_ends_the_session(): void
    {
        $session = $this->sessionFrom($this->signIn()->assertOk());

        $this->asTheBrowser($session)->postJson('/api/v1/auth/logout')->assertOk();

        // The browser may still hold the cookie; it must now open nothing.
        $this->asTheBrowser($session)->getJson('/api/v1/auth/me')->assertUnauthorized();
    }

    public function test_signing_in_from_outside_the_app_is_refused(): void
    {
        CurrentOrg::clear();

        // No Origin, no Referer: a script, not the browser. A token handed to it
        // would be a 30-day credential that shows in no session list and ignores
        // the idle timeout -- the thing this change exists to remove.
        $this->postJson('/api/v1/auth/login', ['email' => self::EMAIL, 'password' => self::PASSWORD])
            ->assertForbidden();

        $this->assertSame(0, $this->maria()->tokens()->count());
    }

    // ── What the cookie has to reach ───────────────────────────────────────

    public function test_the_csrf_cookie_is_served_where_nginx_sends_it(): void
    {
        // nginx hands /api/* to Laravel and everything else to Next.js. Sanctum's
        // default /sanctum/csrf-cookie would land on Next and 404 -- and with it
        // every sign-in, because a cookie-authenticated POST needs that token first.
        $this->fromTheApp()->get('/api/sanctum/csrf-cookie')
            ->assertNoContent()
            ->assertCookie('XSRF-TOKEN');
    }

    public function test_the_session_cookie_authorizes_a_realtime_channel(): void
    {
        $session = $this->sessionFrom($this->signIn()->assertOk());

        // /broadcasting/auth is registered outside the api group, so it does not
        // inherit the session from there. Without its own, every socket goes dark
        // the moment the token is gone.
        $this->asTheBrowser($session)->postJson('/broadcasting/auth', [
            'socket_id' => '1234.5678',
            'channel_name' => 'private-user.'.$this->maria()->id,
        ])->assertOk();
    }

    // ── The one client that keeps a token ──────────────────────────────────

    public function test_a_machine_token_still_signs_in_n8n(): void
    {
        $token = $this->maria()->createToken('n8n')->plainTextToken;

        // n8n calls from a server: no Origin, no cookie, just the Bearer header.
        $this->withToken($token)->getJson('/api/v1/auth/me')->assertOk();
    }

    public function test_a_closed_account_is_refused_on_a_real_bearer_header(): void
    {
        // The held-token refusal was proven with Sanctum::actingAs(), which switches
        // the default guard before the request runs. A real header does not, so the
        // gate is proven here with the header a real client actually sends.
        $maria = $this->maria();
        $token = $maria->createToken('n8n')->plainTextToken;
        $maria->forceFill(['status' => User::STATUS_INACTIVE])->save();

        $this->withToken($token)->getJson('/api/v1/auth/me')->assertForbidden();
    }

    // ── Changing the password ──────────────────────────────────────────────

    public function test_changing_your_password_keeps_you_here_and_ends_every_other_session(): void
    {
        $before = $this->sessionFrom($this->signIn()->assertOk());
        // Signed in and never came back. Sanctum stores the password hash it checks
        // against only on a session's SECOND request, so this one would survive.
        $elsewhere = $this->sessionFrom($this->signIn()->assertOk());

        $here = $this->sessionFrom($this->asTheBrowser($before)->postJson('/api/v1/auth/change-password', [
            'current_password' => self::PASSWORD,
            'password' => 'a-new-one-2026',
            'password_confirmation' => 'a-new-one-2026',
        ])->assertOk());

        $this->asTheBrowser($here)->getJson('/api/v1/auth/me')->assertOk();
        $this->asTheBrowser($elsewhere)->getJson('/api/v1/auth/me')->assertUnauthorized();
        // A copy of this very session's cookie -- what someone who stole it holds --
        // is worth nothing either: the id it names was replaced along with the password.
        $this->asTheBrowser($before)->getJson('/api/v1/auth/me')->assertUnauthorized();
    }

    // ── How long a session lives ───────────────────────────────────────────

    public function test_a_session_nobody_uses_for_two_days_ends(): void
    {
        $this->assertSame(2880, config('session.lifetime'), 'Two days of inactivity, in minutes.');

        $session = $this->sessionFrom($this->signIn()->assertOk());

        $this->travel(2 * 24 * 60 + 1)->minutes();

        $this->asTheBrowser($session)->getJson('/api/v1/auth/me')->assertUnauthorized();
    }

    public function test_a_session_in_use_stays_open_past_two_days(): void
    {
        // Idle, not absolute: someone who works every day is never signed out by it.
        $session = $this->sessionFrom($this->signIn()->assertOk());

        foreach (range(1, 4) as $day) {
            $this->travel(1)->days();
            $this->asTheBrowser($session)->getJson('/api/v1/auth/me')
                ->assertOk("Day {$day}: a session used yesterday must still be open.");
        }
    }

    public function test_even_a_session_in_daily_use_ends_after_thirty_days(): void
    {
        $session = $this->sessionFrom($this->signIn()->assertOk());

        foreach (range(1, 29) as $day) {
            $this->travel(1)->days();
            $this->asTheBrowser($session)->getJson('/api/v1/auth/me')->assertOk();
        }

        // Thirty days and a minute. It was used yesterday, so idle is not what ends it.
        $this->travel(1)->days();
        $this->travel(1)->minutes();
        $this->asTheBrowser($session)->getJson('/api/v1/auth/me')->assertUnauthorized();
    }
}
