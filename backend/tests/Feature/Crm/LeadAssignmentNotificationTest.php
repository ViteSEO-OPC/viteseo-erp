<?php

namespace Tests\Feature\Crm;

use App\Events\NotificationCreated;
use App\Models\User;
use App\Modules\Crm\Events\LeadAssigned;
use App\Modules\Crm\Models\Lead;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Assigning a lead notifies the BD who receives it.
 *
 * Before this existed the handover was announced ONLY as a LeadAssigned broadcast —
 * a toast for a BD who happened to be looking, gone on refresh, and nothing at all
 * for one who was offline. The two now do different jobs: the event is the nudge,
 * the notification is the record.
 *
 * `test_the_notification_is_persisted_even_when_broadcasting_fails` is the one that
 * pins the design down. Illuminate's dispatcher broadcasts BEFORE it runs listeners
 * and SafeBroadcast swallows transport errors, so a listener on LeadAssigned would
 * quietly not run during a Reverb outage. That test fails if anyone moves this back
 * onto the event.
 */
class LeadAssignmentNotificationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    private function admin(): User
    {
        $user = User::factory()->create(['status' => 'active', 'name' => 'Rea Villanueva']);
        $user->assignRole('crm_admin');

        return $user;
    }

    private function bd(string $name = 'Jhamire Aranda'): User
    {
        $user = User::factory()->create(['status' => 'active', 'name' => $name]);
        $user->assignRole('crm_bd');

        return $user;
    }

    private function assign(User $actor, Lead $lead, User $to): \Illuminate\Testing\TestResponse
    {
        Sanctum::actingAs($actor);

        return $this->patchJson("/api/v1/crm/leads/{$lead->id}/assign", ['assigned_to' => $to->id]);
    }

    public function test_assigning_a_lead_notifies_the_receiving_bd(): void
    {
        $admin = $this->admin();
        $bd = $this->bd();
        $lead = Lead::factory()->create(['business_name' => "What's Cookin' Kitchenette Co."]);

        $this->assign($admin, $lead, $bd)->assertOk();

        $this->assertSame(1, $bd->notifications()->count());
        $this->assertSame(1, $bd->unreadNotifications()->count(), 'A new assignment must be unread.');

        $data = $bd->notifications()->first()->data;
        $this->assertSame('lead_assigned', $data['type']);
        $this->assertSame($lead->id, $data['lead_id']);
        $this->assertSame("What's Cookin' Kitchenette Co.", $data['business_name']);
        $this->assertSame($admin->id, $data['assigned_by_id']);
        $this->assertSame('Rea Villanueva', $data['assigned_by_name']);
    }

    /** The actor is represented, and the lead is reachable — both are the point of the row. */
    public function test_the_notification_names_the_lead_and_the_actor_and_links_to_the_lead(): void
    {
        $admin = $this->admin();
        $bd = $this->bd();
        $lead = Lead::factory()->create(['business_name' => 'Acme Dental']);

        $this->assign($admin, $lead, $bd)->assertOk();

        Sanctum::actingAs($bd);
        $row = $this->getJson('/api/v1/crm/notifications')->assertOk()->json('data.0');

        $this->assertSame('New lead assigned: Acme Dental', $row['title']);
        $this->assertSame('Assigned by Rea Villanueva', $row['message']);
        $this->assertSame('/zerem/leads/'.$lead->id, $row['link']);
        $this->assertFalse($row['read']);
    }

    /** Only the new owner is told. An assignment is not an announcement. */
    public function test_only_the_receiving_bd_is_notified(): void
    {
        $admin = $this->admin();
        $bd = $this->bd();
        $otherBd = $this->bd('Someone Else');
        $lead = Lead::factory()->create();

        $this->assign($admin, $lead, $bd)->assertOk();

        $this->assertSame(1, $bd->notifications()->count());
        $this->assertSame(0, $otherBd->notifications()->count());
        $this->assertSame(0, $admin->notifications()->count(), 'The actor does not notify themselves.');
    }

    /** It rides the ONE pipeline: persisting is what pushes it to the bell. */
    public function test_the_notification_is_broadcast_through_the_shared_pipeline(): void
    {
        Event::fake([NotificationCreated::class]);

        $admin = $this->admin();
        $bd = $this->bd();
        $lead = Lead::factory()->create();

        $this->assign($admin, $lead, $bd)->assertOk();

        Event::assertDispatched(
            NotificationCreated::class,
            fn (NotificationCreated $e): bool => $e->userId === (int) $bd->id
                && $e->module === 'crm'
                && $e->payload['type'] === 'lead_assigned'
        );
    }

    /**
     * The reason this is not a listener on LeadAssigned.
     *
     * With broadcasting unavailable the record must still exist — that is what makes
     * it a record. The dispatcher broadcasts before listeners run, so anything hung
     * off the event is skipped when SafeBroadcast catches the failure.
     */
    public function test_the_notification_is_persisted_even_when_broadcasting_fails(): void
    {
        $admin = $this->admin();
        $bd = $this->bd();
        $lead = Lead::factory()->create();

        // A broadcaster that throws, the way an unreachable Reverb does.
        $this->app->extend('Illuminate\Contracts\Broadcasting\Factory', function () {
            return new class
            {
                public function connection($name = null)
                {
                    return $this;
                }

                public function event($event = null)
                {
                    throw new \RuntimeException('Reverb is down');
                }

                public function queue($event)
                {
                    throw new \RuntimeException('Reverb is down');
                }
            };
        });

        $this->assign($admin, $lead, $bd)->assertOk();

        $this->assertSame(
            1,
            $bd->notifications()->count(),
            'The assignment must be recorded even with no realtime transport.',
        );
    }

    /** The toast still fires — the record did not replace the nudge. */
    public function test_the_realtime_lead_assigned_event_still_fires(): void
    {
        Event::fake([LeadAssigned::class]);

        $admin = $this->admin();
        $bd = $this->bd();
        $lead = Lead::factory()->create();

        $this->assign($admin, $lead, $bd)->assertOk();

        Event::assertDispatched(LeadAssigned::class);
    }

    /** Reassignment notifies the new owner each time; the old owner is not re-notified. */
    public function test_reassigning_notifies_the_new_owner_only(): void
    {
        $admin = $this->admin();
        $first = $this->bd('First Owner');
        $second = $this->bd('Second Owner');
        $lead = Lead::factory()->create();

        $this->assign($admin, $lead, $first)->assertOk();
        $this->assign($admin, $lead->fresh(), $second)->assertOk();

        $this->assertSame(1, $first->notifications()->count(), 'The losing owner keeps only their original one.');
        $this->assertSame(1, $second->notifications()->count());
    }

    /** A BD cannot assign leads at all, so no notification can originate from one. */
    public function test_a_bd_cannot_assign_and_therefore_cannot_notify(): void
    {
        $bd = $this->bd();
        $otherBd = $this->bd('Target');
        $lead = Lead::factory()->create();

        $this->assign($bd, $lead, $otherBd)->assertForbidden();

        $this->assertSame(0, $otherBd->notifications()->count());
    }

    /** It shows up where the PO asked for it: the bell feed and the History cursor walk. */
    public function test_it_appears_in_the_feed_and_in_history(): void
    {
        $admin = $this->admin();
        $bd = $this->bd();
        $lead = Lead::factory()->create(['business_name' => 'Findable Co']);

        $this->assign($admin, $lead, $bd)->assertOk();

        Sanctum::actingAs($bd);

        $this->getJson('/api/v1/crm/notifications')
            ->assertOk()
            ->assertJsonPath('data.0.title', 'New lead assigned: Findable Co');

        $this->getJson('/api/v1/crm/notifications?paginate=cursor&per_page=10')
            ->assertOk()
            ->assertJsonPath('data.0.title', 'New lead assigned: Findable Co');

        // And it is filterable by its catalog type, which only works if the enum knows it.
        $this->getJson('/api/v1/crm/notifications?type=lead_assigned')
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }
}
