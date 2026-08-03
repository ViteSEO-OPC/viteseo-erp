<?php

namespace Tests\Feature\Crm;

use App\Models\User;
use App\Modules\Crm\Notifications\PlannerReminderNotification;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Cursor pagination for the History page — the Slice 4 addition to `GET /notifications`.
 *
 * Two things are being protected here. The first is that cursor mode is OPT-IN: the
 * dropdown reads the offset envelope and was not touched, so a request without
 * `paginate=cursor` must still come back offset-shaped.
 *
 * The second is why History needs a cursor at all. The feed grows at the head — Slice 3
 * pushes new notifications to the top in realtime — and offset pagination numbers rows
 * from the head, so anything arriving mid-read shifts every later row down by one and
 * the next page repeats what the reader already saw. A cursor is anchored to a row
 * instead of a count. `test_a_notification_arriving_mid_read_does_not_repeat_a_row`
 * is the test that fails if anyone points History back at offsets.
 */
class NotificationHistoryPaginationTest extends TestCase
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

    /** Creates one notification, with `created_at` controllable so ties can be forced. */
    private function notify(User $user, string $title, ?string $createdAt = null): string
    {
        $id = (string) Str::uuid();

        $user->notifications()->create([
            'id' => $id,
            'type' => PlannerReminderNotification::class,
            'data' => ['type' => 'planner_reminder', 'title' => $title, 'planner_event_id' => 1],
            'read_at' => null,
            'created_at' => $createdAt ?? now(),
            'updated_at' => $createdAt ?? now(),
        ]);

        return $id;
    }

    /**
     * Walks the whole feed in cursor mode and returns every title seen, in order.
     *
     * @return array<int, string>
     */
    private function walkAllPages(int $perPage, array $extraParams = []): array
    {
        $titles = [];
        $cursor = null;
        $guard = 0;

        do {
            $params = ['paginate' => 'cursor', 'per_page' => $perPage] + $extraParams;
            if ($cursor !== null) {
                $params['cursor'] = $cursor;
            }

            $response = $this->getJson('/api/v1/crm/notifications?'.http_build_query($params))->assertOk();

            foreach ($response->json('data') as $row) {
                $titles[] = $row['title'];
            }

            $cursor = $response->json('meta.next_cursor');

            // A cursor that never terminates would otherwise hang the suite.
            $this->assertLessThan(50, ++$guard, 'Cursor pagination did not terminate.');
        } while ($cursor !== null);

        return $titles;
    }

    // ── Backwards compatibility ─────────────────────────────────────────────────

    public function test_without_the_opt_in_the_feed_is_still_offset_paginated(): void
    {
        $me = $this->bd();
        $this->notify($me, 'One');

        Sanctum::actingAs($me);

        $response = $this->getJson('/api/v1/crm/notifications')->assertOk();

        // The envelope the dropdown reads.
        $response->assertJsonPath('meta.current_page', 1);
        $this->assertArrayHasKey('last_page', $response->json('meta'));
        $this->assertArrayNotHasKey('next_cursor', $response->json('meta'));
    }

    public function test_an_unknown_pagination_mode_is_rejected_rather_than_guessed(): void
    {
        Sanctum::actingAs($this->bd());

        $this->getJson('/api/v1/crm/notifications?paginate=infinite')
            ->assertStatus(422)
            ->assertJsonValidationErrors('paginate');
    }

    // ── Cursor mode ─────────────────────────────────────────────────────────────

    public function test_cursor_mode_returns_a_cursor_envelope(): void
    {
        $me = $this->bd();
        $this->notify($me, 'One');
        $this->notify($me, 'Two');

        Sanctum::actingAs($me);

        $response = $this->getJson('/api/v1/crm/notifications?paginate=cursor&per_page=1')->assertOk();

        $this->assertCount(1, $response->json('data'));
        $this->assertNotNull($response->json('meta.next_cursor'), 'A further page exists, so a cursor must be offered.');
        $this->assertArrayNotHasKey('current_page', $response->json('meta'));
    }

    public function test_the_last_page_offers_no_further_cursor(): void
    {
        $me = $this->bd();
        $this->notify($me, 'Only one');

        Sanctum::actingAs($me);

        $this->getJson('/api/v1/crm/notifications?paginate=cursor&per_page=15')
            ->assertOk()
            ->assertJsonPath('meta.next_cursor', null);
    }

    public function test_paging_through_the_feed_yields_every_row_exactly_once(): void
    {
        $me = $this->bd();
        for ($i = 1; $i <= 12; $i++) {
            $this->notify($me, "Item {$i}", now()->subMinutes(60 - $i)->toDateTimeString());
        }

        Sanctum::actingAs($me);

        $titles = $this->walkAllPages(perPage: 5);

        $this->assertCount(12, $titles, 'Every row should appear.');
        $this->assertSame($titles, array_unique($titles), 'No row should appear twice.');
        // Newest first, as the relation orders.
        $this->assertSame('Reminder: Item 12', $titles[0]);
        $this->assertSame('Reminder: Item 1', $titles[11]);
    }

    /**
     * The reason `id` is in the ORDER BY.
     *
     * A scheduler run delivers several reminders inside the same second, so
     * `created_at` ties are ordinary traffic. A cursor over a non-unique key cannot
     * distinguish the tied rows and pages straight past some of them. Remove the
     * `orderBy('id')` from the controller and this test drops rows.
     */
    public function test_rows_sharing_a_timestamp_are_not_skipped_across_a_page_boundary(): void
    {
        $me = $this->bd();
        $sameSecond = now()->subMinute()->toDateTimeString();
        for ($i = 1; $i <= 6; $i++) {
            $this->notify($me, "Tied {$i}", $sameSecond);
        }

        Sanctum::actingAs($me);

        // per_page deliberately smaller than the tie group, so the boundary falls
        // inside it — the exact place a non-unique cursor loses rows.
        $titles = $this->walkAllPages(perPage: 2);

        $this->assertCount(6, $titles, 'Tied rows must not be skipped.');
        $this->assertSame($titles, array_unique($titles), 'Tied rows must not repeat.');
    }

    /**
     * The behaviour offset pagination cannot give History, and the reason this slice
     * introduced a cursor at all.
     */
    public function test_a_notification_arriving_mid_read_does_not_repeat_a_row(): void
    {
        $me = $this->bd();
        for ($i = 1; $i <= 6; $i++) {
            $this->notify($me, "Existing {$i}", now()->subMinutes(30 - $i)->toDateTimeString());
        }

        Sanctum::actingAs($me);

        $first = $this->getJson('/api/v1/crm/notifications?paginate=cursor&per_page=3')->assertOk();
        $firstTitles = array_column($first->json('data'), 'title');
        $cursor = $first->json('meta.next_cursor');

        // Realtime delivers a new notification to the head while the reader sits on page 1.
        $this->notify($me, 'Arrived mid-read');

        $second = $this->getJson('/api/v1/crm/notifications?'.http_build_query([
            'paginate' => 'cursor',
            'per_page' => 3,
            'cursor' => $cursor,
        ]))->assertOk();
        $secondTitles = array_column($second->json('data'), 'title');

        $this->assertSame(
            [],
            array_intersect($firstTitles, $secondTitles),
            'Page 2 repeated a row from page 1 — the cursor shifted with the head insertion.',
        );
        $this->assertNotContains(
            'Reminder: Arrived mid-read',
            $secondTitles,
            'A row newer than the cursor belongs above it, never on a later page.',
        );
    }

    // ── Cursor mode composes with the Slice 2 filters ───────────────────────────

    public function test_a_cursor_walk_respects_the_unread_filter(): void
    {
        $me = $this->bd();
        for ($i = 1; $i <= 4; $i++) {
            $this->notify($me, "Unread {$i}", now()->subMinutes(20 - $i)->toDateTimeString());
        }
        $readId = $this->notify($me, 'Already read');
        $me->notifications()->whereKey($readId)->update(['read_at' => now()]);

        Sanctum::actingAs($me);

        $titles = $this->walkAllPages(perPage: 2, extraParams: ['filter' => 'unread']);

        $this->assertCount(4, $titles);
        $this->assertNotContains('Reminder: Already read', $titles);
    }

    public function test_a_cursor_walk_respects_the_search_term(): void
    {
        $me = $this->bd();
        $this->notify($me, 'Discovery call with Ada', now()->subMinutes(3)->toDateTimeString());
        $this->notify($me, 'Discovery call with Grace', now()->subMinutes(2)->toDateTimeString());
        $this->notify($me, 'Unrelated reminder', now()->subMinute()->toDateTimeString());

        Sanctum::actingAs($me);

        $titles = $this->walkAllPages(perPage: 1, extraParams: ['search' => 'Discovery call']);

        $this->assertCount(2, $titles);
        $this->assertNotContains('Reminder: Unrelated reminder', $titles);
    }

    /** The feed is the caller's own in cursor mode too — paging is not a way around scoping. */
    public function test_a_cursor_walk_never_crosses_into_another_users_feed(): void
    {
        $me = $this->bd();
        $someoneElse = $this->bd();
        $this->notify($me, 'Mine');
        $this->notify($someoneElse, 'Theirs');

        Sanctum::actingAs($me);

        $titles = $this->walkAllPages(perPage: 1);

        $this->assertSame(['Reminder: Mine'], $titles);
    }
}
