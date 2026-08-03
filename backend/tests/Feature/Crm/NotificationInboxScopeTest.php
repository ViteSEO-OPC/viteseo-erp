<?php

namespace Tests\Feature\Crm;

use App\Models\User;
use App\Modules\Crm\Notifications\InboundReplyNotification;
use App\Modules\Crm\Support\NotificationInbox;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The bell dropdown's inbox view — `scope=inbox` and the dismiss mutation behind it
 * (management feedback 2026-07-31: the dropdown is a working inbox, History is the
 * archive).
 *
 * Two things are worth more than the rest here. First, the additive guarantee: every
 * assertion that `scope` absent means the complete feed, because History and the
 * broadcast path both depend on that and neither would fail loudly if it broke.
 * Second, badge/feed agreement — the count and the list must narrow together, since a
 * bell reading "1" over an empty dropdown is the exact bug this scope introduces if
 * the two queries are ever allowed to drift.
 */
class NotificationInboxScopeTest extends TestCase
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

    /**
     * Writes a row as the database channel would. `daysAgo` back-dates `created_at`
     * after the insert — Eloquent stamps it on create, so the window cannot be
     * exercised by passing it in.
     */
    private function notify(User $user, int $leadId, int $daysAgo = 0, ?string $readAt = null): string
    {
        $id = (string) Str::uuid();

        $user->notifications()->create([
            'id' => $id,
            'type' => InboundReplyNotification::class,
            'data' => ['type' => 'inbound_reply', 'lead_id' => $leadId],
            'read_at' => $readAt,
        ]);

        if ($daysAgo > 0) {
            $user->notifications()->whereKey($id)->update([
                'created_at' => now()->subDays($daysAgo),
            ]);
        }

        return $id;
    }

    /** @return array<int, string> */
    private function feedIds(string $query = ''): array
    {
        return array_column(
            $this->getJson('/api/v1/crm/notifications'.$query)->assertOk()->json('data'),
            'id'
        );
    }

    // ── The window ──────────────────────────────────────────────────────────────

    public function test_inbox_scope_hides_notifications_older_than_the_window(): void
    {
        $me = $this->bd();
        $fresh = $this->notify($me, 1, daysAgo: 1);
        $old = $this->notify($me, 2, daysAgo: NotificationInbox::WINDOW_DAYS + 1);

        Sanctum::actingAs($me);

        $this->assertSame([$fresh], $this->feedIds('?scope=inbox'));
        $this->assertContains(
            $old,
            $this->feedIds(),
            'The unscoped feed is History\'s — the window must not reach it.'
        );
    }

    /**
     * The boundary itself. A row from just inside the window is the one most likely to
     * be lost to an off-by-one in the comparison, and it is also the one a reader would
     * most notice missing.
     */
    public function test_the_window_boundary_is_inclusive_of_rows_just_inside_it(): void
    {
        $me = $this->bd();
        // Just inside: WINDOW_DAYS ago plus an hour of slack, so the row is younger
        // than the cutoff without the test depending on sub-second timing.
        $me->notifications()->create([
            'id' => $inside = (string) Str::uuid(),
            'type' => InboundReplyNotification::class,
            'data' => ['type' => 'inbound_reply', 'lead_id' => 1],
        ]);
        $me->notifications()->whereKey($inside)->update([
            'created_at' => now()->subDays(NotificationInbox::WINDOW_DAYS)->addHour(),
        ]);

        Sanctum::actingAs($me);

        $this->assertSame([$inside], $this->feedIds('?scope=inbox'));
    }

    // ── Dismiss ─────────────────────────────────────────────────────────────────

    public function test_dismissing_removes_the_row_from_the_inbox_but_not_from_history(): void
    {
        $me = $this->bd();
        $target = $this->notify($me, 1);
        $kept = $this->notify($me, 2);

        Sanctum::actingAs($me);

        $this->patchJson("/api/v1/crm/notifications/{$target}/dismiss")->assertOk();

        $this->assertSame([$kept], $this->feedIds('?scope=inbox'));

        $history = $this->feedIds();
        $this->assertContains($target, $history, 'History keeps the complete record.');
        $this->assertCount(2, $history);
    }

    /** Dismissing is acknowledging: the badge must not keep counting a row nobody can reach. */
    public function test_dismissing_an_unread_row_also_marks_it_read(): void
    {
        $me = $this->bd();
        $target = $this->notify($me, 1);

        Sanctum::actingAs($me);

        $this->patchJson("/api/v1/crm/notifications/{$target}/dismiss")->assertOk();

        $row = $me->notifications()->find($target);
        $this->assertNotNull($row->dismissed_at);
        $this->assertNotNull($row->read_at, 'A dismissed row can never be left unread.');
    }

    /** A double-click must not back-date the row on every retry. */
    public function test_dismissing_twice_keeps_the_original_timestamps(): void
    {
        $me = $this->bd();
        $target = $this->notify($me, 1, readAt: now()->subDay()->toDateTimeString());

        Sanctum::actingAs($me);

        $this->patchJson("/api/v1/crm/notifications/{$target}/dismiss")->assertOk();
        $first = $me->notifications()->find($target);

        $this->travel(5)->minutes();
        $this->patchJson("/api/v1/crm/notifications/{$target}/dismiss")->assertOk();
        $second = $me->notifications()->find($target);

        $this->assertSame($first->dismissed_at, $second->dismissed_at);
        $this->assertEquals($first->read_at, $second->read_at);
    }

    public function test_cannot_dismiss_another_users_notification(): void
    {
        $me = $this->bd();
        $someoneElse = $this->bd();
        $theirs = $this->notify($someoneElse, 1);

        Sanctum::actingAs($me);

        $this->patchJson("/api/v1/crm/notifications/{$theirs}/dismiss")->assertNotFound();

        $this->assertNull($someoneElse->notifications()->find($theirs)->dismissed_at);
    }

    public function test_dismiss_requires_authentication(): void
    {
        $me = $this->bd();
        $id = $this->notify($me, 1);

        $this->patchJson("/api/v1/crm/notifications/{$id}/dismiss")->assertUnauthorized();
    }

    // ── Badge / feed agreement ──────────────────────────────────────────────────

    public function test_scoped_unread_count_matches_what_the_inbox_shows(): void
    {
        $me = $this->bd();
        $this->notify($me, 1);                                                   // counts
        $dismissed = $this->notify($me, 2);                                      // dismissed below
        $this->notify($me, 3, daysAgo: NotificationInbox::WINDOW_DAYS + 1);      // aged out
        $this->notify($me, 4, readAt: now()->toDateTimeString());                // already read

        Sanctum::actingAs($me);

        $this->patchJson("/api/v1/crm/notifications/{$dismissed}/dismiss")->assertOk();

        $this->getJson('/api/v1/crm/notifications/unread-count?scope=inbox')
            ->assertOk()
            ->assertExactJson(['count' => 1]);

        // The unscoped count is History's and still spans everything unread: the fresh
        // row plus the aged-out one. The dismissed row is no longer among them because
        // dismissing read it.
        $this->getJson('/api/v1/crm/notifications/unread-count')
            ->assertOk()
            ->assertExactJson(['count' => 2]);
    }

    // ── Additivity + validation ─────────────────────────────────────────────────

    /** The inbox scope narrows; it must still compose with the Slice 2 filters. */
    public function test_inbox_scope_composes_with_the_existing_filters(): void
    {
        $me = $this->bd();
        $unread = $this->notify($me, 1);
        $this->notify($me, 2, readAt: now()->toDateTimeString());
        $this->notify($me, 3, daysAgo: NotificationInbox::WINDOW_DAYS + 1);

        Sanctum::actingAs($me);

        $this->assertSame([$unread], $this->feedIds('?scope=inbox&filter=unread&type=inbound_reply'));
    }

    public function test_scope_all_is_identical_to_omitting_it(): void
    {
        $me = $this->bd();
        $this->notify($me, 1);
        $dismissed = $this->notify($me, 2);
        $old = $this->notify($me, 3, daysAgo: NotificationInbox::WINDOW_DAYS + 2);

        Sanctum::actingAs($me);
        $this->patchJson("/api/v1/crm/notifications/{$dismissed}/dismiss")->assertOk();

        $this->assertSame($this->feedIds(), $this->feedIds('?scope=all'));
        $this->assertContains($old, $this->feedIds('?scope=all'));
        $this->assertContains($dismissed, $this->feedIds('?scope=all'));
    }

    public function test_an_unknown_scope_is_rejected_rather_than_silently_ignored(): void
    {
        Sanctum::actingAs($this->bd());

        $this->getJson('/api/v1/crm/notifications?scope=archive')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('scope');

        $this->getJson('/api/v1/crm/notifications/unread-count?scope=archive')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('scope');
    }

    public function test_the_resource_exposes_dismissal_state(): void
    {
        $me = $this->bd();
        $target = $this->notify($me, 1);

        Sanctum::actingAs($me);

        $before = $this->getJson('/api/v1/crm/notifications')->assertOk()->json('data.0');
        $this->assertFalse($before['dismissed']);
        $this->assertNull($before['dismissed_at']);

        $this->patchJson("/api/v1/crm/notifications/{$target}/dismiss")->assertOk();

        $after = $this->getJson('/api/v1/crm/notifications')->assertOk()->json('data.0');
        $this->assertTrue($after['dismissed']);
        $this->assertNotNull($after['dismissed_at']);
    }
}
