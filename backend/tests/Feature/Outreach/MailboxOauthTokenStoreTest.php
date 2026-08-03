<?php

namespace Tests\Feature\Outreach;

use App\Modules\Crm\Models\MailboxOauthToken;
use App\Modules\Crm\Services\Mail\MailboxOauthTokenStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * The per-mailbox OAuth token store — the persistence seam a per-tenant Google credential source
 * will sit on top of.
 *
 * Two behaviours here are worth more than the rest and are pinned hardest:
 *
 *  - REFRESH-TOKEN PRESERVATION. Google issues a refresh token once and omits it from every later
 *    refresh response. A store that wrote payloads through verbatim would null the column on the
 *    first refresh and silently disconnect the mailbox until someone re-consented.
 *  - THE EXPIRY SKEW. A token with seconds left is already dead by the time a request carrying it
 *    lands at Google, so "expired" has to mean "expiring soon" too.
 *
 * These are pure persistence tests: the store never contacts Google, so nothing here is faked. The
 * live Gmail credential path (service-account delegation) stores nothing and is unaffected — see
 * MailboxSyncResolutionTest, which continues to pin it.
 */
class MailboxOauthTokenStoreTest extends TestCase
{
    use RefreshDatabase;

    private MailboxOauthTokenStore $store;

    protected function setUp(): void
    {
        parent::setUp();

        $this->store = new MailboxOauthTokenStore;
    }

    // ── Lookup ──────────────────────────────────────────────────────────────────────────────────

    /** "Not connected" is a null, not an exception — it is the state every mailbox starts in. */
    public function test_an_unconnected_mailbox_has_no_stored_grant(): void
    {
        $this->assertNull($this->store->find('nobody@company.test'));
        $this->assertFalse($this->store->has('nobody@company.test'));
    }

    public function test_a_stored_grant_is_found_by_mailbox(): void
    {
        $this->store->put('crm@company.test', 'access-1', 'refresh-1');

        $this->assertSame('crm@company.test', $this->store->find('crm@company.test')?->email);
        $this->assertTrue($this->store->has('crm@company.test'));
    }

    /**
     * Addresses arrive from config, from Google and from operators typing them; the column is
     * uniquely indexed, so a case difference must reach the same row rather than race it.
     */
    public function test_lookup_and_storage_are_case_insensitive(): void
    {
        $this->store->put('  CRM@Company.Test  ', 'access-1', 'refresh-1');

        $this->assertSame('crm@company.test', MailboxOauthToken::query()->value('email'));
        $this->assertNotNull($this->store->find('crm@company.test'));
        $this->assertNotNull($this->store->find('CRM@COMPANY.TEST'));
    }

    // ── Writing ─────────────────────────────────────────────────────────────────────────────────

    public function test_a_first_consent_creates_one_row_with_the_provider_recorded(): void
    {
        $expiry = Carbon::now()->addHour();

        $token = $this->store->put('crm@company.test', 'access-1', 'refresh-1', $expiry);

        $this->assertSame(1, MailboxOauthToken::query()->count());
        $this->assertSame('gmail', $token->provider);
        $this->assertSame('access-1', $token->access_token);
        $this->assertSame('refresh-1', $token->refresh_token);
        // To the second: the column is a `timestamp`, so the stored expiry is truncated — which
        // errs earlier than the real one, i.e. on the safe side of a refresh.
        $this->assertSame($expiry->format('Y-m-d H:i:s'), $token->expires_at?->format('Y-m-d H:i:s'));
    }

    /** One row per address: re-writing a mailbox updates it rather than accumulating grants. */
    public function test_a_later_write_updates_the_same_row(): void
    {
        $this->store->put('crm@company.test', 'access-1', 'refresh-1');
        $this->store->put('crm@company.test', 'access-2');

        $this->assertSame(1, MailboxOauthToken::query()->count());
        $this->assertSame('access-2', $this->store->find('crm@company.test')?->access_token);
    }

    /**
     * THE bug this store exists to prevent. A refresh response carries a new access token and no
     * refresh token; keeping the stored one is what stops the first refresh from disconnecting the
     * mailbox for good.
     */
    public function test_a_refresh_without_a_new_refresh_token_keeps_the_stored_one(): void
    {
        $this->store->put('crm@company.test', 'access-1', 'refresh-1');

        $this->store->put('crm@company.test', 'access-2', null);

        $token = $this->store->find('crm@company.test');
        $this->assertSame('access-2', $token?->access_token);
        $this->assertSame('refresh-1', $token?->refresh_token);
    }

    /** Same rule for a blank string — an absent field and an empty one mean the same thing here. */
    public function test_a_blank_refresh_token_is_treated_as_absent_not_as_a_clear(): void
    {
        $this->store->put('crm@company.test', 'access-1', 'refresh-1');

        $this->store->put('crm@company.test', 'access-2', '   ');

        $this->assertSame('refresh-1', $this->store->find('crm@company.test')?->refresh_token);
    }

    /** Re-consent DOES send a refresh token, and that one must win — otherwise it could never rotate. */
    public function test_a_re_consent_with_a_new_refresh_token_replaces_the_stored_one(): void
    {
        $this->store->put('crm@company.test', 'access-1', 'refresh-1');

        $this->store->put('crm@company.test', 'access-2', 'refresh-2');

        $this->assertSame('refresh-2', $this->store->find('crm@company.test')?->refresh_token);
    }

    /**
     * A row with no usable access token is worse than no row: has() would report the mailbox as
     * connected while nothing could authenticate as it.
     */
    public function test_an_empty_access_token_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->store->put('crm@company.test', '   ');
    }

    public function test_a_blank_mailbox_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->store->put('   ', 'access-1');
    }

    // ── Disconnecting ───────────────────────────────────────────────────────────────────────────

    /**
     * Disconnect means the credential is gone, not archived — a retained refresh token is a live
     * credential that no longer shows up anywhere in the CRM.
     */
    public function test_forgetting_a_mailbox_removes_the_credential_entirely(): void
    {
        $this->store->put('crm@company.test', 'access-1', 'refresh-1');

        $this->assertTrue($this->store->forget('crm@company.test'));
        $this->assertSame(0, MailboxOauthToken::query()->withoutGlobalScopes()->count());
        $this->assertNull($this->store->find('crm@company.test'));
    }

    /** The return value distinguishes a real disconnect from a repeat click, without a second query. */
    public function test_forgetting_an_unconnected_mailbox_reports_that_nothing_was_removed(): void
    {
        $this->assertFalse($this->store->forget('nobody@company.test'));
    }

    /** One mailbox disconnecting must not touch another's grant. */
    public function test_forgetting_one_mailbox_leaves_the_others_connected(): void
    {
        $this->store->put('crm@company.test', 'access-1', 'refresh-1');
        $this->store->put('sales@company.test', 'access-2', 'refresh-2');

        $this->store->forget('crm@company.test');

        $this->assertNull($this->store->find('crm@company.test'));
        $this->assertSame('access-2', $this->store->find('sales@company.test')?->access_token);
    }

    // ── Expiry and skew ─────────────────────────────────────────────────────────────────────────

    public function test_a_token_with_an_hour_left_is_not_expired(): void
    {
        $token = $this->store->put('crm@company.test', 'access-1', 'refresh-1', Carbon::now()->addHour());

        $this->assertFalse($this->store->isExpired($token));
        $this->assertTrue($this->store->hasUsableAccessToken('crm@company.test'));
    }

    public function test_a_token_past_its_expiry_is_expired(): void
    {
        $token = $this->store->put('crm@company.test', 'access-1', 'refresh-1', Carbon::now()->subMinute());

        $this->assertTrue($this->store->isExpired($token));
        $this->assertFalse($this->store->hasUsableAccessToken('crm@company.test'));
    }

    /**
     * The skew itself: a token that is still technically valid but expires inside the window counts
     * as expired, so it is refreshed instead of being raced to Google.
     */
    public function test_a_token_expiring_inside_the_skew_window_is_already_treated_as_expired(): void
    {
        $token = $this->store->put(
            'crm@company.test',
            'access-1',
            'refresh-1',
            Carbon::now()->addSeconds(MailboxOauthTokenStore::EXPIRY_SKEW_SECONDS - 5),
        );

        $this->assertTrue($this->store->isExpired($token));
    }

    /** …and just outside it is still usable, so the skew cannot silently swallow a token's whole life. */
    public function test_a_token_expiring_just_outside_the_skew_window_is_still_usable(): void
    {
        $token = $this->store->put(
            'crm@company.test',
            'access-1',
            'refresh-1',
            Carbon::now()->addSeconds(MailboxOauthTokenStore::EXPIRY_SKEW_SECONDS + 30),
        );

        $this->assertFalse($this->store->isExpired($token));
    }

    /** The skew is an operational choice, so it is overridable — and the override is what applies. */
    public function test_the_skew_is_configurable_per_instance(): void
    {
        $token = $this->store->put('crm@company.test', 'access-1', 'refresh-1', Carbon::now()->addMinutes(5));

        $generous = new MailboxOauthTokenStore(600);

        $this->assertFalse($this->store->isExpired($token));
        $this->assertTrue($generous->isExpired($token));
        $this->assertSame(600, $generous->skewSeconds());
    }

    /** Unknown lifetime is treated as expired rather than optimistically sent to Google. */
    public function test_a_token_with_no_recorded_expiry_is_treated_as_expired(): void
    {
        $token = $this->store->put('crm@company.test', 'access-1', 'refresh-1');

        $this->assertNull($token->expires_at);
        $this->assertTrue($this->store->isExpired($token));
    }

    /** A grant recorded without an access token cannot authenticate anything. */
    public function test_a_grant_with_no_access_token_is_expired(): void
    {
        $token = new MailboxOauthToken([
            'email' => 'crm@company.test',
            'provider' => 'gmail',
            'refresh_token' => 'refresh-1',
            'expires_at' => Carbon::now()->addHour(),
        ]);

        $this->assertTrue($this->store->isExpired($token));
    }

    public function test_a_lifetime_in_seconds_becomes_an_absolute_expiry(): void
    {
        $this->travelTo(Carbon::parse('2026-08-03 09:00:00'));

        $this->assertTrue(
            Carbon::parse('2026-08-03 10:00:00')->equalTo($this->store->expiryFromLifetime(3600)),
        );
    }

    /** A missing or nonsensical `expires_in` is recorded as unknown — which already means expired. */
    public function test_a_missing_or_zero_lifetime_has_no_expiry(): void
    {
        $this->assertNull($this->store->expiryFromLifetime(null));
        $this->assertNull($this->store->expiryFromLifetime(0));
        $this->assertNull($this->store->expiryFromLifetime(-1));
    }

    // ── Secrecy ─────────────────────────────────────────────────────────────────────────────────

    /** Ciphertext at rest: a database dump must not hand over live credentials. */
    public function test_tokens_are_encrypted_in_the_database(): void
    {
        $this->store->put('crm@company.test', 'access-1', 'refresh-1');

        $row = DB::table('crm_mailbox_oauth_tokens')->where('email', 'crm@company.test')->first();

        $this->assertNotSame('access-1', $row->access_token);
        $this->assertNotSame('refresh-1', $row->refresh_token);
        $this->assertStringNotContainsString('access-1', (string) $row->access_token);
        $this->assertStringNotContainsString('refresh-1', (string) $row->refresh_token);
    }

    /** One stray toArray() in a log line or an API resource must not leak a live refresh token. */
    public function test_tokens_never_appear_in_a_serialized_model(): void
    {
        $token = $this->store->put('crm@company.test', 'access-1', 'refresh-1');

        $this->assertArrayNotHasKey('access_token', $token->toArray());
        $this->assertArrayNotHasKey('refresh_token', $token->toArray());
        $this->assertStringNotContainsString('access-1', $token->toJson());
        $this->assertStringNotContainsString('refresh-1', $token->toJson());
    }
}
