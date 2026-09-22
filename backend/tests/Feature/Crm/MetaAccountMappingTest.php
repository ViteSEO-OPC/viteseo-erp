<?php

declare(strict_types=1);

namespace Tests\Feature\Crm;

use App\Models\Org;
use App\Modules\Crm\Console\Commands\ProvisionMetaAccountCommand;
use App\Modules\Crm\Models\Conversation;
use App\Modules\Crm\Models\ConversationMessage;
use App\Modules\Crm\Models\ProviderAccount;
use App\Modules\Crm\Services\Inbound\Meta\MetaAccountResolver;
use App\Support\CurrentOrg;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\Inbound\Meta\MetaEnvelopes;
use Tests\Support\Inbound\Meta\SignsMetaDeliveries;
use Tests\TestCase;

/**
 * Whose delivery is this? — the only question a public webhook cannot answer from its own contents.
 *
 * The headline test is `test_one_delivery_for_two_pages_lands_in_two_orgs`: a single signed delivery
 * carrying two customers' Pages must leave each customer's message in their own tenant and nowhere
 * else. It reads the rows with DB::table deliberately, bypassing the BelongsToOrg scope, because a
 * scoped read would hide exactly the leak it is looking for.
 */
class MetaAccountMappingTest extends TestCase
{
    use RefreshDatabase;
    use SignsMetaDeliveries;

    protected function setUp(): void
    {
        parent::setUp();
        $this->enableMetaWebhook();
    }

    private function resolver(): MetaAccountResolver
    {
        return $this->app->make(MetaAccountResolver::class);
    }

    /** @return array<string, mixed> */
    private function rows(): array
    {
        return DB::table('crm_messages')
            ->join('crm_conversations', 'crm_conversations.id', '=', 'crm_messages.conversation_id')
            ->select('crm_messages.org_id', 'crm_messages.external_message_id', 'crm_conversations.external_conversation_id')
            ->get()
            ->keyBy('external_message_id')
            ->all();
    }

    public function test_a_connected_page_resolves_to_its_org(): void
    {
        $org = $this->tenant();
        $this->connectMetaResource($org->id, MetaEnvelopes::PAGE_A);

        $this->assertSame($org->id, $this->resolver()->orgFor('page', MetaEnvelopes::PAGE_A));
    }

    public function test_a_connected_instagram_account_resolves_to_its_org(): void
    {
        $org = $this->tenant();
        $this->connectMetaResource($org->id, MetaEnvelopes::IG_A, 'instagram_business_account');

        $this->assertSame($org->id, $this->resolver()->orgFor('instagram_business_account', MetaEnvelopes::IG_A));
        $this->assertNull($this->resolver()->orgFor('page', MetaEnvelopes::IG_A), 'the resource TYPE is part of identity');
    }

    public function test_an_unknown_resource_resolves_to_nothing(): void
    {
        $this->assertNull($this->resolver()->orgFor('page', '999999999'));
    }

    public function test_a_deactivated_resource_stops_resolving(): void
    {
        $account = $this->connectMetaResource($this->tenant()->id, MetaEnvelopes::PAGE_A);

        $account->is_active = false;
        $account->save();

        $this->assertNull($this->resolver()->orgFor('page', MetaEnvelopes::PAGE_A));
    }

    /** The database refuses a second owner, so ambiguity never reaches request time. */
    public function test_two_orgs_cannot_claim_one_resource(): void
    {
        $this->connectMetaResource($this->tenant()->id, MetaEnvelopes::PAGE_A);
        $other = Org::create(['name' => 'Second Tenant Inc', 'is_active' => true]);

        $this->expectException(UniqueConstraintViolationException::class);

        $this->connectMetaResource($other->id, MetaEnvelopes::PAGE_A);
    }

    /** An unmapped Page is acknowledged and ingests nothing — never guessed into a tenant. */
    public function test_an_unmapped_page_writes_nothing_and_is_acknowledged(): void
    {
        $this->postSigned(MetaEnvelopes::page(MetaEnvelopes::PAGE_A, [MetaEnvelopes::textItem()]))
            ->assertOk();

        $this->assertSame(0, ConversationMessage::count());
        $this->assertSame(0, Conversation::count());
    }

    /**
     * THE cross-tenant test. One signed delivery, two Pages, two orgs.
     */
    public function test_one_delivery_for_two_pages_lands_in_two_orgs(): void
    {
        $orgA = $this->tenant();
        $orgB = Org::create(['name' => 'Second Tenant Inc', 'is_active' => true]);

        $this->connectMetaResource($orgA->id, MetaEnvelopes::PAGE_A);
        $this->connectMetaResource($orgB->id, MetaEnvelopes::PAGE_B);

        $this->postSigned(MetaEnvelopes::twoPages())->assertOk();

        $rows = $this->rows();

        $this->assertCount(2, $rows);
        $this->assertSame($orgA->id, (int) $rows['mid.pageA']->org_id);
        $this->assertSame($orgB->id, (int) $rows['mid.pageB']->org_id);

        // And each thread belongs to its own Page, so neither org can see the other's conversation.
        $this->assertStringContainsString(MetaEnvelopes::PAGE_A, $rows['mid.pageA']->external_conversation_id);
        $this->assertStringContainsString(MetaEnvelopes::PAGE_B, $rows['mid.pageB']->external_conversation_id);

        $this->assertSame(2, DB::table('crm_conversations')->count());
    }

    /** A delivery where only one of the two Pages is connected leaves the other out entirely. */
    public function test_an_unmapped_page_does_not_ride_along_with_a_mapped_one(): void
    {
        $orgA = $this->tenant();
        $this->connectMetaResource($orgA->id, MetaEnvelopes::PAGE_A);

        $this->postSigned(MetaEnvelopes::twoPages())->assertOk();

        $rows = $this->rows();

        $this->assertCount(1, $rows);
        $this->assertArrayHasKey('mid.pageA', $rows);
        $this->assertArrayNotHasKey('mid.pageB', $rows, 'an unconnected Page must not borrow the other tenant');
    }

    /** The pin the test process was running under is restored after the webhook finishes. */
    public function test_the_tenant_pin_is_restored_after_dispatch(): void
    {
        $orgA = $this->tenant();
        $orgB = Org::create(['name' => 'Second Tenant Inc', 'is_active' => true]);
        $this->connectMetaResource($orgB->id, MetaEnvelopes::PAGE_B);

        $before = CurrentOrg::id();
        $this->assertSame($orgA->id, $before);

        $this->postSigned(MetaEnvelopes::twoPages())->assertOk();

        $this->assertSame($before, CurrentOrg::id(), 'runAs must not leak the resolved tenant');
    }

    /** Nothing in a payload can name a tenant; the mapping is the only source. */
    public function test_a_payload_cannot_choose_its_org(): void
    {
        $orgA = $this->tenant();
        $orgB = Org::create(['name' => 'Second Tenant Inc', 'is_active' => true]);
        $this->connectMetaResource($orgA->id, MetaEnvelopes::PAGE_A);

        $envelope = MetaEnvelopes::page(MetaEnvelopes::PAGE_A, [MetaEnvelopes::textItem()]);
        $envelope['org_id'] = $orgB->id;
        $envelope['entry'][0]['org_id'] = $orgB->id;
        $envelope['entry'][0]['messaging'][0]['org_id'] = $orgB->id;
        $envelope['entry'][0]['messaging'][0]['tenant_id'] = $orgB->id;

        $this->postSigned($envelope)->assertOk();

        $this->assertSame($orgA->id, (int) DB::table('crm_messages')->value('org_id'));
    }

    /**
     * An item must belong to the resource that was resolved to the tenant.
     *
     * The tenant is decided from `entry[].id`, while the thread identity comes from the item's own
     * `recipient.id`. In any normal delivery those are the same Page — but nothing in the provider's
     * format guarantees it, and if they diverge the turn is written under the org that owns the
     * ENTRY while being keyed by a different Page's id. That is not a cross-tenant write, but it is
     * a thread whose identity disagrees with the tenancy decision that placed it, and the next
     * person to touch either half would have to notice that on their own.
     */
    public function test_an_item_addressed_to_another_resource_is_refused(): void
    {
        $orgA = $this->tenant();
        $this->connectMetaResource($orgA->id, MetaEnvelopes::PAGE_A);

        // entry = Page A (mapped, resolves to org A); the item claims Page B as its recipient.
        $mismatched = MetaEnvelopes::textItem('mid.mismatch');
        $mismatched['recipient']['id'] = MetaEnvelopes::PAGE_B;

        $this->postSigned(MetaEnvelopes::page(MetaEnvelopes::PAGE_A, [
            MetaEnvelopes::textItem('mid.honest', 'belongs here'),
            $mismatched,
        ]))->assertOk();

        $this->assertSame(
            ['mid.honest'],
            DB::table('crm_messages')->orderBy('id')->pluck('external_message_id')->all(),
            'the mismatched item is refused; its honest sibling still lands',
        );

        $this->assertSame(
            1,
            DB::table('crm_conversations')->count(),
            'and no thread keyed by another resource is created',
        );
    }

    // ------------------------------------------------------------------ provisioning command

    public function test_the_command_connects_a_page_to_an_explicit_org(): void
    {
        $org = $this->tenant();

        $this->artisan('crm:meta-account', ['--org' => (string) $org->id, '--page' => MetaEnvelopes::PAGE_A, '--label' => 'Main Page'])
            ->assertSuccessful();

        $account = ProviderAccount::query()->firstOrFail();

        $this->assertSame($org->id, (int) $account->org_id);
        $this->assertSame('page', $account->resource_type);
        $this->assertTrue($account->is_active);
        $this->assertSame('Main Page', $account->label);
    }

    public function test_the_command_refuses_to_move_a_resource_between_orgs(): void
    {
        $orgA = $this->tenant();
        $orgB = Org::create(['name' => 'Second Tenant Inc', 'is_active' => true]);

        $this->connectMetaResource($orgA->id, MetaEnvelopes::PAGE_A);

        $this->artisan('crm:meta-account', ['--org' => (string) $orgB->id, '--page' => MetaEnvelopes::PAGE_A])
            ->expectsOutputToContain('already connected to organisation')
            ->assertFailed();

        $this->assertSame($orgA->id, (int) ProviderAccount::query()->firstOrFail()->org_id);
    }

    public function test_the_command_requires_exactly_one_resource(): void
    {
        $org = (string) $this->tenant()->id;

        $this->artisan('crm:meta-account', ['--org' => $org])->assertFailed();
        $this->artisan('crm:meta-account', ['--org' => $org, '--page' => 'p', '--ig' => 'i'])->assertFailed();
        $this->artisan('crm:meta-account', ['--page' => MetaEnvelopes::PAGE_A])->assertFailed();
    }

    public function test_the_command_deactivates_without_deleting(): void
    {
        $org = $this->tenant();
        $this->connectMetaResource($org->id, MetaEnvelopes::PAGE_A);

        $this->artisan('crm:meta-account', ['--page' => MetaEnvelopes::PAGE_A, '--deactivate' => true])->assertSuccessful();

        $account = ProviderAccount::query()->firstOrFail();

        $this->assertFalse($account->is_active);
        $this->assertSame($org->id, (int) $account->org_id, 'the row and its history survive');
    }

    /** `orgs.name` has no unique index, so an ambiguous name must stop rather than pick. */
    public function test_the_command_refuses_an_ambiguous_org_name(): void
    {
        $this->tenant()->forceFill(['name' => 'Twinned Org'])->save();
        Org::create(['name' => 'Twinned Org', 'is_active' => true]);

        $this->artisan('crm:meta-account', ['--org' => 'Twinned Org', '--page' => MetaEnvelopes::PAGE_A])
            ->expectsOutputToContain('Several organisations are named')
            ->assertFailed();

        $this->assertSame(0, ProviderAccount::query()->count());
    }

    /** This phase has nowhere to put a Page Access Token, deliberately. */
    public function test_the_command_offers_no_place_for_a_token(): void
    {
        $definition = $this->app->make(ProvisionMetaAccountCommand::class)
            ->getDefinition()
            ->getOptions();

        foreach (array_keys($definition) as $option) {
            $this->assertStringNotContainsString('token', (string) $option);
            $this->assertStringNotContainsString('secret', (string) $option);
        }

        $this->assertSame(
            ['id', 'org_id', 'provider', 'resource_type', 'external_id', 'label', 'is_active', 'created_at', 'updated_at'],
            Schema::getColumnListing('crm_provider_accounts'),
            'the mapping table holds ownership only — no credential column exists',
        );
    }
}
