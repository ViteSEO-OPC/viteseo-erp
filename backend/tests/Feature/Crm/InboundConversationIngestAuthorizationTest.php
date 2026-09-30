<?php

declare(strict_types=1);

namespace Tests\Feature\Crm;

use App\Models\User;
use App\Modules\Crm\Models\Conversation;
use App\Modules\Crm\Models\Lead;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * The blast radius of a leaked integration credential, stated as tests.
 *
 * The account can write one kind of row. Everything else in the CRM must answer 403 — and the
 * point of testing it from the OUTSIDE, through real routes, is that a permission list is a claim
 * while an HTTP status is evidence.
 */
class InboundConversationIngestAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    private const INGEST = '/api/v1/crm/automation/conversations/ingest';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    private function integrationAccount(): User
    {
        // UserFactory already places the user in the existing org — attaching again would collide
        // with the one-org-per-user unique index.
        $user = User::factory()->create(['email' => 'meta-inbound-org'.$this->tenant()->id.'@crm.local']);
        $user->givePermissionTo(Permission::findOrCreate('crm.conversations.ingest', 'web'));

        return $user;
    }

    private function someoneElsesLead(): Lead
    {
        $bd = User::factory()->create();
        $bd->assignRole('crm_bd');

        return Lead::factory()->create([
            'email' => 'private@example.com',
            'status' => 'new',
            'assigned_to' => $bd->id,
            'created_by' => $bd->id,
        ]);
    }

    /** 15 */
    public function test_the_integration_account_cannot_read_a_lead(): void
    {
        $lead = $this->someoneElsesLead();
        Sanctum::actingAs($this->integrationAccount());

        $this->getJson('/api/v1/crm/leads/'.$lead->id)->assertForbidden();
        $this->getJson('/api/v1/crm/leads')->assertForbidden();
    }

    /** 14 */
    public function test_the_integration_account_cannot_edit_a_lead(): void
    {
        $lead = $this->someoneElsesLead();
        Sanctum::actingAs($this->integrationAccount());

        $this->patchJson('/api/v1/crm/leads/'.$lead->id, ['business_name' => 'Renamed By Integration'])
            ->assertForbidden();

        $this->assertNotSame('Renamed By Integration', $lead->fresh()->business_name);
    }

    /** 17 */
    public function test_the_integration_account_cannot_assign_lead_ownership(): void
    {
        $lead = $this->someoneElsesLead();
        $originalOwner = $lead->assigned_to;
        $integration = $this->integrationAccount();

        Sanctum::actingAs($integration);

        $this->patchJson('/api/v1/crm/leads/'.$lead->id.'/assign', ['assigned_to' => $integration->id])
            ->assertForbidden();

        $this->assertSame($originalOwner, $lead->fresh()->assigned_to);
    }

    /** The other narrow write surfaces stay shut too. */
    public function test_the_integration_account_cannot_create_leads_notes_or_send_email(): void
    {
        $lead = $this->someoneElsesLead();
        Sanctum::actingAs($this->integrationAccount());

        $this->postJson('/api/v1/crm/leads', ['business_name' => 'X', 'contact_name' => 'Y'])->assertForbidden();
        $this->postJson('/api/v1/crm/leads/'.$lead->id.'/notes', ['body' => 'inserted by integration'])->assertForbidden();
        $this->postJson('/api/v1/crm/automation/leads/'.$lead->id.'/emails', ['purpose' => 'intro'])->assertForbidden();
        $this->postJson('/api/v1/crm/leads/import', [])->assertForbidden();
    }

    /** 16 — ingesting a message that MATCHES a lead still changes nothing about that lead. */
    public function test_ingesting_never_changes_lead_status_or_ownership(): void
    {
        $lead = $this->someoneElsesLead();

        // Read the BEFORE snapshot from the database, not from the in-memory model: LeadObserver
        // recomputes the Lead Score when the lead is created and writes it quietly, so an in-memory
        // copy is already stale before ingest is even called. Comparing DB state to DB state is what
        // makes a difference here attributable to ingest.
        $columns = ['status', 'assigned_to', 'priority', 'score', 'email_outreach', 'cold_calling'];
        $before = $lead->fresh()->only($columns);

        Sanctum::actingAs($this->integrationAccount());

        $this->postJson(self::INGEST, [
            'provider' => 'meta',
            'channel' => 'messenger',
            'external_conversation_id' => 't_auth_1',
            'external_message_id' => 'mid_auth_1',
            'sender_email' => 'private@example.com',
            'text' => 'hello',
        ])->assertCreated();

        $after = $lead->fresh()->only($columns);

        $this->assertSame($before, $after, 'ingest may associate a conversation, never move the lead');
        $this->assertSame($lead->id, Conversation::firstOrFail()->lead_id);
    }

    /** The account holds exactly one ability, and it is not a lead ability. */
    public function test_the_integration_account_holds_no_role_and_one_permission(): void
    {
        $user = $this->integrationAccount();

        $this->assertTrue($user->getRoleNames()->isEmpty());
        $this->assertSame(['crm.conversations.ingest'], $user->getDirectPermissions()->pluck('name')->all());
        $this->assertFalse($user->can('crm.leads.view'));
        $this->assertFalse($user->can('crm.leads.edit'));
        $this->assertFalse($user->can('crm.lead.notes.create'));
        $this->assertFalse($user->can('crm.outreach.automation.send'));
    }
}
