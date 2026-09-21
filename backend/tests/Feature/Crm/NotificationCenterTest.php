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
 * The in-app Notification Center API. These endpoints shipped in RC2 with no test
 * coverage at all; this pins the behaviour that was already relied on before the
 * presenter registry moved the presentation logic, so the refactor is provably
 * behaviour-preserving rather than merely believed to be.
 *
 * The isolation tests matter most. Ownership is enforced by the Notifiable relation
 * rather than a policy, which is correct but easy to undo by "simplifying" a query
 * to Notification::find() — these tests fail loudly if anyone does.
 */
class NotificationCenterTest extends TestCase
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
     * Writes a row exactly as Laravel's database channel would, so the tests exercise
     * the real stored shape without needing each producer's dependencies.
     *
     * @param  array<string, mixed>  $data
     */
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

    // ── Feed ────────────────────────────────────────────────────────────────────

    public function test_index_returns_only_the_callers_own_notifications(): void
    {
        $me = $this->bd();
        $someoneElse = $this->bd();

        $mine = $this->notify($me, 'inbound_reply', ['lead_id' => 7, 'from_name' => 'Ada']);
        $this->notify($someoneElse, 'inbound_reply', ['lead_id' => 9, 'from_name' => 'Grace']);

        Sanctum::actingAs($me);

        $response = $this->getJson('/api/v1/crm/notifications')->assertOk();

        $ids = array_column($response->json('data'), 'id');
        $this->assertSame([$mine], $ids, 'The feed must never include another user\'s notifications.');
    }

    public function test_unread_count_is_scoped_and_ignores_read_rows(): void
    {
        $me = $this->bd();
        $someoneElse = $this->bd();

        $this->notify($me, 'inbound_reply', ['lead_id' => 1]);
        $this->notify($me, 'inbound_reply', ['lead_id' => 2]);
        $this->notify($me, 'inbound_reply', ['lead_id' => 3], readAt: now()->toDateTimeString());
        $this->notify($someoneElse, 'inbound_reply', ['lead_id' => 4]);

        Sanctum::actingAs($me);

        $this->getJson('/api/v1/crm/notifications/unread-count')
            ->assertOk()
            ->assertExactJson(['count' => 2]);
    }

    // ── Read state ──────────────────────────────────────────────────────────────

    public function test_marking_one_read_updates_only_that_row(): void
    {
        $me = $this->bd();
        $target = $this->notify($me, 'inbound_reply', ['lead_id' => 1]);
        $other = $this->notify($me, 'inbound_reply', ['lead_id' => 2]);

        Sanctum::actingAs($me);

        $this->patchJson("/api/v1/crm/notifications/{$target}/read")->assertOk();

        $this->assertNotNull($me->notifications()->find($target)->read_at);
        $this->assertNull($me->notifications()->find($other)->read_at);
    }

    /** Ownership is enforced by the relation — another user's id must not resolve at all. */
    public function test_cannot_mark_another_users_notification_read(): void
    {
        $me = $this->bd();
        $someoneElse = $this->bd();
        $theirs = $this->notify($someoneElse, 'inbound_reply', ['lead_id' => 1]);

        Sanctum::actingAs($me);

        $this->patchJson("/api/v1/crm/notifications/{$theirs}/read")->assertNotFound();

        $this->assertNull($someoneElse->notifications()->find($theirs)->read_at);
    }

    public function test_mark_all_read_clears_the_callers_unread_and_leaves_others_alone(): void
    {
        $me = $this->bd();
        $someoneElse = $this->bd();

        $this->notify($me, 'inbound_reply', ['lead_id' => 1]);
        $this->notify($me, 'planner_reminder', ['planner_event_id' => 5]);
        $theirs = $this->notify($someoneElse, 'inbound_reply', ['lead_id' => 2]);

        Sanctum::actingAs($me);

        $this->patchJson('/api/v1/crm/notifications/read-all')->assertOk();

        $this->assertSame(0, $me->unreadNotifications()->count());
        $this->assertNull(
            $someoneElse->notifications()->find($theirs)->read_at,
            'Mark-all must be scoped to the caller.'
        );
    }

    public function test_endpoints_require_authentication(): void
    {
        $this->getJson('/api/v1/crm/notifications')->assertUnauthorized();
        $this->getJson('/api/v1/crm/notifications/unread-count')->assertUnauthorized();
        $this->patchJson('/api/v1/crm/notifications/read-all')->assertUnauthorized();
    }

    // ── Presentation ────────────────────────────────────────────────────────────

    public function test_inbound_reply_is_presented_with_title_message_and_lead_link(): void
    {
        $me = $this->bd();
        $this->notify($me, 'inbound_reply', [
            'lead_id' => 42,
            'from_name' => 'Ada Lovelace',
            'subject' => 'Re: your proposal',
        ]);

        Sanctum::actingAs($me);

        $row = $this->getJson('/api/v1/crm/notifications')->assertOk()->json('data.0');

        $this->assertSame('inbound_reply', $row['type']);
        $this->assertSame('New reply from Ada Lovelace', $row['title']);
        $this->assertSame('Re: your proposal', $row['message']);
        $this->assertSame('/zerem/leads/42', $row['link']);
        $this->assertFalse($row['read']);
    }

    /** A reminder attached to a lead opens the LEAD, not the Planner. */
    public function test_planner_reminder_prefers_the_lead_link_over_the_planner(): void
    {
        $me = $this->bd();
        $this->notify($me, 'planner_reminder', [
            'planner_event_id' => 5,
            'lead_id' => 42,
            'title' => 'Discovery call',
            'event_type' => 'consultation',
            'lead_name' => 'Acme Co',
        ]);

        Sanctum::actingAs($me);

        $row = $this->getJson('/api/v1/crm/notifications')->assertOk()->json('data.0');

        $this->assertSame('Reminder: Discovery call', $row['title']);
        $this->assertSame('Consultation · Acme Co', $row['message']);
        $this->assertSame('/zerem/leads/42', $row['link']);
    }

    public function test_planner_reminder_without_a_lead_falls_back_to_the_planner(): void
    {
        $me = $this->bd();
        $this->notify($me, 'planner_reminder', ['planner_event_id' => 5, 'title' => 'Team sync']);

        Sanctum::actingAs($me);

        $row = $this->getJson('/api/v1/crm/notifications')->assertOk()->json('data.0');

        $this->assertSame('/zerem/planner', $row['link']);
        $this->assertNull($row['message'], 'No event type or lead means no second line, not an empty one.');
    }

    /**
     * Rows whose type is not in the catalog must still render. This is the only path
     * the fallbacks exist for — a retired type, or a row written before the catalog.
     */
    public function test_an_unregistered_type_still_renders_and_keeps_its_legacy_link(): void
    {
        $me = $this->bd();
        $me->notifications()->create([
            'id' => (string) Str::uuid(),
            'type' => InboundReplyNotification::class,
            'data' => ['type' => 'retired_type', 'lead_id' => 8],
        ]);

        Sanctum::actingAs($me);

        $row = $this->getJson('/api/v1/crm/notifications')->assertOk()->json('data.0');

        $this->assertSame('Retired Type', $row['title']);
        $this->assertNull($row['message']);
        $this->assertSame('/zerem/leads/8', $row['link']);
    }
}
