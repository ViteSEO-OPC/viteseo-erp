<?php

namespace Tests\Feature\Crm;

use App\Models\User;
use App\Modules\Crm\Notifications\InboundReplyNotification;
use App\Modules\Crm\Notifications\PlannerReminderNotification;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Feed filtering, search, page size and the mark-unread endpoint — the Slice 2
 * additions to `GET /notifications`.
 *
 * The first test is the important one: with no parameters the endpoint must behave
 * exactly as it did before these existed, because the shipped bell and dropdown call
 * it that way and were not touched. Everything else here is opt-in.
 */
class NotificationFilteringTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    private function bd(): User
    {
        $user = User::factory()->create(['status' => 'active']);
        $user->assignRole('crm_bd');

        return $user;
    }

    /** @param array<string, mixed> $data */
    private function notify(User $user, string $type, array $data = [], ?string $readAt = null): string
    {
        $id = (string) Str::uuid();

        $user->notifications()->create([
            'id' => $id,
            'type' => $type === 'inbound_reply'
                ? InboundReplyNotification::class
                : PlannerReminderNotification::class,
            'data' => ['type' => $type] + $data,
            'read_at' => $readAt,
        ]);

        return $id;
    }

    /** Seeds one of each type, one read and one unread, for the filter assertions. */
    private function seedMixedFeed(User $user): void
    {
        $this->notify($user, 'inbound_reply', ['lead_id' => 1, 'from_name' => 'Ada Lovelace', 'subject' => 'Re: proposal']);
        $this->notify($user, 'planner_reminder', ['planner_event_id' => 5, 'title' => 'Discovery call'], readAt: now()->toDateTimeString());
    }

    // ── Backwards compatibility ─────────────────────────────────────────────────

    public function test_an_unfiltered_request_still_returns_the_whole_feed(): void
    {
        $me = $this->bd();
        $this->seedMixedFeed($me);

        Sanctum::actingAs($me);

        $response = $this->getJson('/api/v1/crm/notifications')->assertOk();

        $this->assertCount(2, $response->json('data'));
        // The offset envelope the existing dropdown was built against is unchanged.
        $this->assertSame(15, $response->json('meta.per_page'));
        $this->assertSame(2, $response->json('meta.total'));
    }

    // ── Filters ─────────────────────────────────────────────────────────────────

    public function test_unread_filter_excludes_read_notifications(): void
    {
        $me = $this->bd();
        $this->seedMixedFeed($me);

        Sanctum::actingAs($me);

        $rows = $this->getJson('/api/v1/crm/notifications?filter=unread')->assertOk()->json('data');

        $this->assertCount(1, $rows);
        $this->assertSame('inbound_reply', $rows[0]['type']);
        $this->assertFalse($rows[0]['read']);
    }

    public function test_filter_all_is_equivalent_to_no_filter(): void
    {
        $me = $this->bd();
        $this->seedMixedFeed($me);

        Sanctum::actingAs($me);

        $this->assertCount(2, $this->getJson('/api/v1/crm/notifications?filter=all')->assertOk()->json('data'));
    }

    public function test_type_filter_returns_only_that_catalog_type(): void
    {
        $me = $this->bd();
        $this->seedMixedFeed($me);

        Sanctum::actingAs($me);

        $rows = $this->getJson('/api/v1/crm/notifications?type=planner_reminder')->assertOk()->json('data');

        $this->assertCount(1, $rows);
        $this->assertSame('planner_reminder', $rows[0]['type']);
    }

    /** Filters narrow the caller's own feed; they can never widen it to anyone else's. */
    public function test_filters_stay_scoped_to_the_caller(): void
    {
        $me = $this->bd();
        $someoneElse = $this->bd();
        $this->notify($someoneElse, 'inbound_reply', ['lead_id' => 1, 'from_name' => 'Ada Lovelace']);

        Sanctum::actingAs($me);

        foreach (['?filter=unread', '?type=inbound_reply', '?search=Ada'] as $qs) {
            $this->assertCount(
                0,
                $this->getJson("/api/v1/crm/notifications{$qs}")->assertOk()->json('data'),
                "Filter {$qs} leaked another user's notifications."
            );
        }
    }

    // ── Search ──────────────────────────────────────────────────────────────────

    public function test_search_matches_text_inside_the_stored_payload(): void
    {
        $me = $this->bd();
        $this->seedMixedFeed($me);

        Sanctum::actingAs($me);

        $rows = $this->getJson('/api/v1/crm/notifications?search=Lovelace')->assertOk()->json('data');

        $this->assertCount(1, $rows);
        $this->assertSame('New reply from Ada Lovelace', $rows[0]['title']);
    }

    public function test_search_that_matches_nothing_returns_an_empty_feed(): void
    {
        $me = $this->bd();
        $this->seedMixedFeed($me);

        Sanctum::actingAs($me);

        $this->assertCount(0, $this->getJson('/api/v1/crm/notifications?search=zzzznomatch')->assertOk()->json('data'));
    }

    /** A `%` in the term must match literally, not act as "everything". */
    public function test_search_treats_sql_wildcards_as_literal_text(): void
    {
        $me = $this->bd();
        $this->seedMixedFeed($me);

        Sanctum::actingAs($me);

        $this->assertCount(
            0,
            $this->getJson('/api/v1/crm/notifications?search=%')->assertOk()->json('data'),
            'An unescaped % would have matched every notification.'
        );
    }

    public function test_filters_combine(): void
    {
        $me = $this->bd();
        $this->seedMixedFeed($me);
        // Read, so the unread filter must exclude it despite matching the search.
        $this->notify($me, 'inbound_reply', ['lead_id' => 2, 'from_name' => 'Ada Byron'], readAt: now()->toDateTimeString());

        Sanctum::actingAs($me);

        $rows = $this->getJson('/api/v1/crm/notifications?filter=unread&type=inbound_reply&search=Ada')
            ->assertOk()->json('data');

        $this->assertCount(1, $rows);
        $this->assertSame('New reply from Ada Lovelace', $rows[0]['title']);
    }

    // ── Validation ──────────────────────────────────────────────────────────────

    public function test_an_unknown_type_is_rejected_rather_than_returning_an_empty_feed(): void
    {
        Sanctum::actingAs($this->bd());

        $this->getJson('/api/v1/crm/notifications?type=not_a_real_type')
            ->assertStatus(422)
            ->assertJsonValidationErrors('type');
    }

    public function test_an_unknown_filter_value_is_rejected(): void
    {
        Sanctum::actingAs($this->bd());

        $this->getJson('/api/v1/crm/notifications?filter=archived')
            ->assertStatus(422)
            ->assertJsonValidationErrors('filter');
    }

    public function test_page_size_is_capped(): void
    {
        Sanctum::actingAs($this->bd());

        $this->getJson('/api/v1/crm/notifications?per_page=5000')
            ->assertStatus(422)
            ->assertJsonValidationErrors('per_page');

        $this->getJson('/api/v1/crm/notifications?per_page=50')->assertOk();
    }

    public function test_per_page_controls_the_page_size(): void
    {
        $me = $this->bd();
        $this->seedMixedFeed($me);

        Sanctum::actingAs($me);

        $response = $this->getJson('/api/v1/crm/notifications?per_page=1')->assertOk();

        $this->assertCount(1, $response->json('data'));
        $this->assertSame(2, $response->json('meta.total'));
    }

    // ── Mark unread ─────────────────────────────────────────────────────────────

    public function test_marking_unread_restores_the_row_to_the_unread_feed(): void
    {
        $me = $this->bd();
        $id = $this->notify($me, 'inbound_reply', ['lead_id' => 1], readAt: now()->toDateTimeString());

        Sanctum::actingAs($me);

        $this->patchJson("/api/v1/crm/notifications/{$id}/unread")->assertOk();

        $this->assertNull($me->notifications()->find($id)->read_at);
        $this->getJson('/api/v1/crm/notifications/unread-count')->assertExactJson(['count' => 1]);
    }

    public function test_cannot_mark_another_users_notification_unread(): void
    {
        $me = $this->bd();
        $someoneElse = $this->bd();
        $theirs = $this->notify($someoneElse, 'inbound_reply', ['lead_id' => 1], readAt: now()->toDateTimeString());

        Sanctum::actingAs($me);

        $this->patchJson("/api/v1/crm/notifications/{$theirs}/unread")->assertNotFound();

        $this->assertNotNull($someoneElse->notifications()->find($theirs)->read_at);
    }

    public function test_mark_unread_requires_authentication(): void
    {
        $this->patchJson('/api/v1/crm/notifications/'.Str::uuid().'/unread')->assertUnauthorized();
    }
}
