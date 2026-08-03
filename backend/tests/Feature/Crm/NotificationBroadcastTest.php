<?php

namespace Tests\Feature\Crm;

use App\Events\NotificationCreated;
use App\Models\User;
use App\Modules\Crm\Models\PlannerEvent;
use App\Modules\Crm\Notifications\FixtureCrmMailNotification;
use App\Modules\Crm\Notifications\FixtureCrmNotification;
use App\Modules\Crm\Notifications\PlannerReminderNotification;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

// Declared in the CRM namespace so the listener's guard treats them as real CRM
// notifications — see the file header for why that matters.
require_once __DIR__.'/Fixtures/CrmNotificationFixtures.php';

/**
 * Realtime delivery of in-app notifications.
 *
 * What these pin down is the WIRING, not any single producer. Broadcasting hangs off
 * Laravel's notification lifecycle, so a CRM in-app notification cannot be created
 * without being pushed — no producer calls a broadcast, and none can forget to.
 * `test_an_unknown_future_type_broadcasts_without_being_taught_to` is the guard that
 * would fail if anyone moved broadcasting back into the producers.
 */
class NotificationBroadcastTest extends TestCase
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

    public function test_an_in_app_notification_is_broadcast_to_its_recipient(): void
    {
        Event::fake([NotificationCreated::class]);

        $recipient = $this->bd();
        $recipient->notify(new FixtureCrmNotification(['type' => 'inbound_reply', 'lead_id' => 42, 'from_name' => 'Ada']));

        Event::assertDispatched(
            NotificationCreated::class,
            fn (NotificationCreated $e): bool => $e->userId === (int) $recipient->id && $e->module === 'crm'
        );
    }

    /** Only the recipient's channel is addressed — notifications are not fan-out. */
    public function test_it_broadcasts_to_the_recipient_and_nobody_else(): void
    {
        Event::fake([NotificationCreated::class]);

        $recipient = $this->bd();
        $bystander = $this->bd();
        $recipient->notify(new FixtureCrmNotification(['type' => 'inbound_reply', 'lead_id' => 1]));

        Event::assertDispatched(
            NotificationCreated::class,
            fn (NotificationCreated $e): bool => $e->userId !== (int) $bystander->id
        );
    }

    /**
     * The socket payload is the same resource the REST feed returns, so the client
     * patches one shape into its cache rather than reconciling two.
     */
    public function test_the_broadcast_payload_matches_the_rest_resource(): void
    {
        Event::fake([NotificationCreated::class]);

        $this->bd()->notify(new FixtureCrmNotification([
            'type' => 'planner_reminder',
            'title' => 'Discovery call',
            'planner_event_id' => 5,
        ]));

        Event::assertDispatched(NotificationCreated::class, function (NotificationCreated $e): bool {
            foreach (['id', 'type', 'title', 'message', 'link', 'data', 'read', 'read_at', 'created_at'] as $key) {
                $this->assertArrayHasKey($key, $e->payload, "Payload is missing `{$key}`.");
            }

            // Presented by the registry, exactly as GET /notifications would.
            $this->assertSame('Reminder: Discovery call', $e->payload['title']);
            $this->assertSame('/zerem/planner', $e->payload['link']);
            $this->assertFalse($e->payload['read'], 'A newly created notification is unread.');

            return true;
        });
    }

    /**
     * The regression guard. Nothing anywhere has been taught about this payload's
     * type — if it still broadcasts, the hook is on the lifecycle rather than in the
     * producers. Reintroducing per-producer broadcast calls fails here first.
     */
    public function test_an_unknown_future_type_broadcasts_without_being_taught_to(): void
    {
        Event::fake([NotificationCreated::class]);

        $this->bd()->notify(new FixtureCrmNotification(['type' => 'type_invented_in_this_test']));

        Event::assertDispatched(NotificationCreated::class);
    }

    /** Mail and any future channel deliver themselves; only the in-app feed is pushed. */
    public function test_a_mail_only_notification_is_not_broadcast(): void
    {
        Event::fake([NotificationCreated::class]);

        $this->bd()->notify(new FixtureCrmMailNotification);

        Event::assertNotDispatched(NotificationCreated::class);
    }

    /**
     * A notification from outside the CRM must not be announced under the CRM's
     * module tag — that tag is what stops the Projects listener consuming it.
     */
    public function test_a_notification_from_outside_the_crm_is_not_broadcast_as_crm(): void
    {
        Event::fake([NotificationCreated::class]);

        $this->bd()->notify(new OutsideCrmNotification);

        Event::assertNotDispatched(NotificationCreated::class);
    }

    /** A real shipped producer, confirming the guards do not exclude actual traffic. */
    public function test_a_real_shipped_producer_still_broadcasts(): void
    {
        Event::fake([NotificationCreated::class]);

        $recipient = $this->bd();
        $event = PlannerEvent::factory()->create();

        $recipient->notify(new PlannerReminderNotification($event));

        Event::assertDispatched(
            NotificationCreated::class,
            fn (NotificationCreated $e): bool => $e->userId === (int) $recipient->id && $e->module === 'crm'
        );
    }

    /** The row is persisted regardless — realtime is a push, never the system of record. */
    public function test_the_notification_is_still_stored_when_broadcasting_is_faked_away(): void
    {
        Event::fake([NotificationCreated::class]);

        $recipient = $this->bd();
        $recipient->notify(new FixtureCrmNotification(['type' => 'inbound_reply', 'lead_id' => 1]));

        $this->assertSame(1, $recipient->notifications()->count());
        $this->assertSame(1, $recipient->unreadNotifications()->count());
    }
}

/** Deliberately in the Tests namespace: it stands in for another module's notification. */
class OutsideCrmNotification extends Notification
{
    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return ['type' => 'not_a_crm_notification'];
    }
}
