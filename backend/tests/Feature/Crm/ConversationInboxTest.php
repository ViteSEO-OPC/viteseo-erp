<?php

declare(strict_types=1);

namespace Tests\Feature\Crm;

use App\Models\Org;
use App\Models\User;
use App\Modules\Crm\Models\Conversation;
use App\Modules\Crm\Models\ConversationMessage;
use App\Modules\Crm\Models\Lead;
use App\Support\CurrentOrg;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * The conversation inbox, tested from the outside.
 *
 * THE TESTS THAT MATTER MOST ARE THE ISOLATION ONES. This is the first screen that puts real
 * customer message content on a page, and the first read surface over a table that spans tenants.
 * A list endpoint that quietly returned another organisation's conversation would be the single
 * worst defect this module could ship, so it is checked by enumeration — asking for the other
 * tenant's row by its real id — rather than by trusting that a filter is present.
 *
 * Synthetic content only. No production message text appears anywhere in this file.
 */
class ConversationInboxTest extends TestCase
{
    use RefreshDatabase;

    private const LIST = '/api/v1/crm/conversations';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    private function reader(): User
    {
        $user = User::factory()->create();
        $user->givePermissionTo(Permission::findOrCreate('crm.conversations.view', 'web'));

        return $user;
    }

    /** A conversation with one inbound message, in whichever org is currently pinned. */
    private function conversation(string $channel = 'messenger', string $body = 'Synthetic enquiry text', ?int $leadId = null): Conversation
    {
        $conversation = Conversation::query()->create([
            'provider' => 'meta',
            'channel' => $channel,
            'external_conversation_id' => 'thread_'.$channel.'_'.uniqid(),
            'external_contact_id' => 'sender_'.uniqid(),
            'status' => 'open',
            'started_at' => now()->subMinutes(5),
            'last_message_at' => now(),
        ]);

        if ($leadId !== null) {
            $conversation->lead_id = $leadId;
            $conversation->save();
        }

        $message = new ConversationMessage([
            'provider' => 'meta',
            'channel' => $channel,
            'external_message_id' => 'mid_'.uniqid(),
            'direction' => 'inbound',
            'sender_external_id' => 'psid_secret_identifier',
            'recipient_external_id' => 'page_1',
            'message_type' => 'text',
            'body' => $body,
            'payload' => ['secret_envelope' => 'RAW_PAYLOAD_CANARY_5521'],
            'occurred_at' => now(),
            'received_at' => now(),
        ]);
        $message->conversation_id = $conversation->id;
        $message->idempotency_key = hash('sha256', $conversation->id.$body.uniqid());
        $message->save();

        return $conversation;
    }

    /** 1 — an authorised user sees their own organisation's conversations. */
    public function test_an_authorised_user_lists_their_own_conversations(): void
    {
        $this->conversation('messenger', 'Magkano po ang package?');
        Sanctum::actingAs($this->reader());

        $response = $this->getJson(self::LIST)->assertOk();

        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.provider', 'meta');
        $response->assertJsonPath('data.0.channel', 'messenger');
        $response->assertJsonPath('data.0.latestMessage.preview', 'Magkano po ang package?');
        $response->assertJsonPath('data.0.leadLinked', false);
    }

    /** 2 — and can open one. */
    public function test_an_authorised_user_opens_their_own_conversation(): void
    {
        $conversation = $this->conversation('messenger', 'Detail view body');
        Sanctum::actingAs($this->reader());

        $this->getJson(self::LIST.'/'.$conversation->id)
            ->assertOk()
            ->assertJsonPath('data.id', (string) $conversation->id)
            ->assertJsonPath('data.channel', 'messenger')
            ->assertJsonPath('data.messageCount', 1)
            ->assertJsonPath('data.messages.0.body', 'Detail view body')
            ->assertJsonPath('data.messages.0.direction', 'inbound');
    }

    /** Without the ability, the inbox is closed. */
    public function test_a_user_without_the_ability_is_refused(): void
    {
        $conversation = $this->conversation();
        Sanctum::actingAs(User::factory()->create());

        $this->getJson(self::LIST)->assertForbidden();
        $this->getJson(self::LIST.'/'.$conversation->id)->assertForbidden();
    }

    public function test_an_unauthenticated_request_is_refused(): void
    {
        $conversation = $this->conversation();

        $this->getJson(self::LIST)->assertUnauthorized();
        $this->getJson(self::LIST.'/'.$conversation->id)->assertUnauthorized();
    }

    /**
     * 3, 4 — THE ISOLATION TEST. Another organisation's conversation is not filtered out of a
     * list; it is unreachable, including by asking for its real id directly.
     */
    public function test_another_orgs_conversation_is_invisible_and_unreachable(): void
    {
        $orgA = $this->tenant();

        // Build a conversation inside a second tenant, then come back to the first.
        $orgB = Org::create(['name' => 'Second Tenant Inc', 'is_active' => true]);
        $foreign = CurrentOrg::runAs($orgB->id, fn () => $this->conversation('messenger', 'Other tenant content'));

        $this->assertSame($orgB->id, (int) DB::table('crm_conversations')->where('id', $foreign->id)->value('org_id'));
        CurrentOrg::set($orgA->id);

        $mine = $this->conversation('messenger', 'My own content');
        Sanctum::actingAs($this->reader());

        $list = $this->getJson(self::LIST)->assertOk();
        $list->assertJsonCount(1, 'data');
        $list->assertJsonPath('data.0.id', (string) $mine->id);
        $list->assertJsonMissing(['id' => (string) $foreign->id]);

        // Direct enumeration: a 404, not a filtered 200 — the row is out of reach, not merely hidden.
        $this->getJson(self::LIST.'/'.$foreign->id)->assertNotFound();
    }

    /** 5 — messages are protected through their conversation's ownership. */
    public function test_foreign_messages_never_appear_in_a_thread(): void
    {
        $orgA = $this->tenant();
        $orgB = Org::create(['name' => 'Second Tenant Inc', 'is_active' => true]);
        CurrentOrg::runAs($orgB->id, fn () => $this->conversation('messenger', 'Foreign message body'));
        CurrentOrg::set($orgA->id);

        $mine = $this->conversation('messenger', 'Mine only');
        Sanctum::actingAs($this->reader());

        $response = $this->getJson(self::LIST.'/'.$mine->id)->assertOk();

        $response->assertJsonCount(1, 'data.messages');
        $response->assertJsonPath('data.messages.0.body', 'Mine only');
        $response->assertDontSee('Foreign message body');
    }

    /** 6 — the raw provider envelope is never serialized, on either endpoint. */
    public function test_the_raw_payload_and_internal_material_are_never_returned(): void
    {
        $conversation = $this->conversation();
        Sanctum::actingAs($this->reader());

        foreach ([self::LIST, self::LIST.'/'.$conversation->id] as $url) {
            $body = $this->getJson($url)->assertOk()->getContent();

            $this->assertStringNotContainsString('RAW_PAYLOAD_CANARY_5521', $body, 'no raw payload');
            $this->assertStringNotContainsString('payload', $body, 'not even the key');
            $this->assertStringNotContainsString('idempotency', $body, 'no replay material');
            $this->assertStringNotContainsString('psid_secret_identifier', $body, 'no sender identifier');
            $this->assertStringNotContainsString('external_conversation_id', $body);
            $this->assertStringNotContainsString($conversation->external_contact_id, $body);
        }
    }

    /** 7 — pagination bounds the read. */
    public function test_the_list_is_paginated(): void
    {
        for ($i = 0; $i < 7; $i++) {
            $this->conversation('messenger', 'Body '.$i);
        }
        Sanctum::actingAs($this->reader());

        $page = $this->getJson(self::LIST.'?per_page=3')->assertOk();

        $page->assertJsonCount(3, 'data');
        $page->assertJsonPath('meta.total', 7);
        $page->assertJsonPath('meta.per_page', 3);

        $this->getJson(self::LIST.'?per_page=999')->assertStatus(422);
    }

    /** 8 — Messenger and Instagram stay distinct, and the channel filter works. */
    public function test_messenger_and_instagram_remain_distinct(): void
    {
        $this->conversation('messenger', 'From Messenger');
        $this->conversation('instagram_dm', 'From Instagram');
        Sanctum::actingAs($this->reader());

        $this->getJson(self::LIST)->assertOk()->assertJsonCount(2, 'data');

        $messenger = $this->getJson(self::LIST.'?channel=messenger')->assertOk();
        $messenger->assertJsonCount(1, 'data');
        $messenger->assertJsonPath('data.0.channel', 'messenger');
        $messenger->assertJsonPath('data.0.latestMessage.preview', 'From Messenger');

        $instagram = $this->getJson(self::LIST.'?channel=instagram_dm')->assertOk();
        $instagram->assertJsonCount(1, 'data');
        $instagram->assertJsonPath('data.0.channel', 'instagram_dm');
        $instagram->assertJsonPath('data.0.latestMessage.preview', 'From Instagram');
    }

    /** A filter that matches nothing is an empty list, not an error. */
    public function test_a_filter_matching_nothing_returns_an_empty_list(): void
    {
        $this->conversation('messenger');
        Sanctum::actingAs($this->reader());

        $this->getJson(self::LIST.'?channel=whatsapp')->assertOk()->assertJsonCount(0, 'data');
    }

    /** The linked/unlinked filter — the inbox's main triage question. */
    public function test_the_lead_link_filter_works(): void
    {
        $owner = User::factory()->create();
        $lead = Lead::factory()->create(['assigned_to' => $owner->id, 'created_by' => $owner->id]);

        $this->conversation('messenger', 'Unlinked thread');
        $this->conversation('messenger', 'Linked thread', $lead->id);

        Sanctum::actingAs($this->reader());

        $this->getJson(self::LIST.'?lead=linked')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.leadLinked', true);
        $this->getJson(self::LIST.'?lead=unlinked')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.leadLinked', false);
    }

    /** A request may not choose its own tenant. */
    public function test_a_request_cannot_supply_an_org(): void
    {
        $this->conversation();
        Sanctum::actingAs($this->reader());

        $this->getJson(self::LIST.'?org_id=2')->assertStatus(422);
        $this->getJson(self::LIST.'?tenant_id=2')->assertStatus(422);
    }

    /**
     * The lead summary respects LeadPolicy: a viewer who may not see the lead learns only that the
     * thread is linked.
     */
    public function test_a_lead_summary_is_withheld_from_a_viewer_who_cannot_see_the_lead(): void
    {
        $owner = User::factory()->create();
        $owner->assignRole('crm_bd');
        $lead = Lead::factory()->create([
            'business_name' => 'Confidential Client Ltd',
            'assigned_to' => $owner->id,
            'created_by' => $owner->id,
        ]);

        $conversation = $this->conversation('messenger', 'Linked to someone else', $lead->id);

        // A BD who owns nothing: holds crm.leads.view but not this lead.
        $stranger = $this->reader();
        $stranger->assignRole('crm_bd');
        Sanctum::actingAs($stranger);

        $response = $this->getJson(self::LIST.'/'.$conversation->id)->assertOk();

        $response->assertJsonPath('data.leadLinked', true);
        $response->assertJsonPath('data.lead', null);
        $response->assertDontSee('Confidential Client Ltd');
    }

    /** The thread is ordered oldest-first so it reads like a conversation. */
    public function test_the_thread_is_ordered_oldest_first(): void
    {
        $conversation = $this->conversation('messenger', 'First message');

        $second = new ConversationMessage([
            'provider' => 'meta', 'channel' => 'messenger',
            'external_message_id' => 'mid_second', 'direction' => 'inbound',
            'message_type' => 'text', 'body' => 'Second message',
            'occurred_at' => now()->addMinute(), 'received_at' => now()->addMinute(),
        ]);
        $second->conversation_id = $conversation->id;
        $second->idempotency_key = hash('sha256', 'second'.$conversation->id);
        $second->save();

        Sanctum::actingAs($this->reader());

        $this->getJson(self::LIST.'/'.$conversation->id)
            ->assertOk()
            ->assertJsonPath('data.messages.0.body', 'First message')
            ->assertJsonPath('data.messages.1.body', 'Second message');
    }

    /** The list does not degrade into a query per conversation. */
    public function test_the_list_does_not_issue_a_query_per_conversation(): void
    {
        for ($i = 0; $i < 6; $i++) {
            $this->conversation('messenger', 'Body '.$i);
        }
        Sanctum::actingAs($this->reader());

        DB::enableQueryLog();
        $this->getJson(self::LIST)->assertOk();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        // Auth, permissions, the page, its count, the leads and the latest-message lookup — a
        // constant handful. A per-row query would put this well above the bound.
        $this->assertLessThan(15, $count, "the list issued {$count} queries for 6 conversations");
    }
}
