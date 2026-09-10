<?php

declare(strict_types=1);

namespace Tests\Feature\Platform;

use App\Models\User;
use App\Support\MachineTokens;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Every token says, in its own row, when it stops working.
 *
 * Tokens used to expire by a rule nobody could see from the row: the guard
 * compared created_at against sanctum.expiration (30 days) at request time, and
 * expires_at sat NULL. That was harmless while every token was a browser token.
 * Browsers now use sessions, and the tokens left are machine credentials -- the
 * Zerem scraper, n8n -- for which a 30-day life means a silent outage every month
 * that nobody re-mints them.
 *
 * So the hidden rule goes, and each token carries its own expiry instead: 90 days
 * for a machine token, 30 for anything minted without saying. A token with no
 * expiry at all cannot be created.
 *
 * The trap in removing the hidden rule is resurrection. Every existing row has
 * expires_at NULL, and with the rule gone NULL means "never". So the migration
 * first writes into each row the expiry the rule implied -- created_at + 30 days
 * -- and nothing dead today comes back, and nothing alive today dies early.
 */
class EveryTokenCarriesItsOwnExpiryTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION = 'migrations/2026_09_10_010000_every_token_carries_its_own_expiry.php';

    private function service(string $email = 'scraper-service@crm.local'): User
    {
        return User::factory()->create(['email' => $email, 'status' => 'active']);
    }

    /**
     * One request with a bearer token, as a real client sends it.
     *
     * forgetGuards(): the sanctum guard is a singleton that caches the user it
     * resolved, so without it every request after the first would pass on the
     * previous request's answer -- and an expired token would look alive.
     */
    private function me(string $token): TestResponse
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($token)->getJson('/api/v1/auth/me');
    }

    /** A token as production holds them today: no expiry of its own, aged by created_at alone. */
    private function legacyToken(User $user, string $name, int $daysOld): string
    {
        $token = $user->createToken($name);
        DB::table('personal_access_tokens')->where('id', $token->accessToken->id)
            ->update(['expires_at' => null, 'created_at' => now()->subDays($daysOld)]);

        return $token->plainTextToken;
    }

    private function runTheMigration(): void
    {
        (require database_path(self::MIGRATION))->up();
    }

    public function test_a_machine_token_lives_ninety_days_not_thirty(): void
    {
        $token = MachineTokens::issue($this->service(), 'scraper');

        $this->travel(60)->days();
        $this->me($token)->assertOk();

        $this->travel(31)->days();
        $this->me($token)->assertUnauthorized();
    }

    public function test_a_token_minted_without_saying_gets_thirty_days(): void
    {
        $token = $this->service()->createToken('adhoc');

        $this->assertNotNull($token->accessToken->fresh()->expires_at, 'No token may exist without an expiry.');

        $this->travel(29)->days();
        $this->me($token->plainTextToken)->assertOk();

        $this->travel(2)->days();
        $this->me($token->plainTextToken)->assertUnauthorized();
    }

    public function test_migrating_kills_nothing_alive_and_revives_nothing_dead(): void
    {
        $service = $this->service();
        $alive = $this->legacyToken($service, 'scraper', 24);       // the scraper's token today
        $dead = $this->legacyToken($service, 'deploy-verify', 57);  // expired in July

        $this->runTheMigration();

        $this->me($alive)->assertOk();
        $this->me($dead)->assertUnauthorized();

        // And the living one still stops on the day it always would have: day 30.
        $this->travel(7)->days();
        $this->me($alive)->assertUnauthorized();
    }

    public function test_browser_tokens_are_revoked_outright(): void
    {
        $browser = $this->legacyToken($this->service('juan@bituin.ph'), 'web', 3);
        $scraper = $this->legacyToken($this->service(), 'scraper', 3);

        $this->runTheMigration();

        // Kept in localStorage, readable by any script on the page: whatever copies
        // exist stop opening anything the moment this runs. Machine tokens stay.
        $this->assertSame(0, DB::table('personal_access_tokens')->where('name', 'web')->count());
        $this->me($browser)->assertUnauthorized();
        $this->me($scraper)->assertOk();
    }

    public function test_the_n8n_command_mints_a_ninety_day_token(): void
    {
        $this->artisan('n8n:token')->assertSuccessful();

        $token = User::where('email', config('n8n.service_account.email'))->firstOrFail()
            ->tokens()->latest('id')->firstOrFail();

        $this->assertEqualsWithDelta(now()->addDays(90)->getTimestamp(), $token->expires_at->getTimestamp(), 5);
    }

    public function test_a_service_token_is_rotated_from_the_command_line(): void
    {
        $service = $this->service();
        $old = MachineTokens::issue($service, 'scraper');
        $other = MachineTokens::issue($service, 'something-else');

        $this->artisan('tokens:issue', ['email' => $service->email, 'name' => 'scraper', '--rotate' => true])
            ->assertSuccessful();

        $this->me($old)->assertUnauthorized();
        $this->me($other)->assertOk();
        $this->assertSame(1, $service->tokens()->where('name', 'scraper')->count());
    }
}
