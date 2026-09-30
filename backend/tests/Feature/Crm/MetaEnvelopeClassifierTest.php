<?php

declare(strict_types=1);

namespace Tests\Feature\Crm;

use App\Modules\Crm\Services\Inbound\Adapters\RejectionCode;
use App\Modules\Crm\Services\Inbound\Meta\MetaEnvelopeClassifier;
use App\Modules\Crm\Services\Inbound\Meta\MetaResourceGroup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\Inbound\Meta\MetaEnvelopes;
use Tests\TestCase;

/**
 * How one signed delivery is cut into units that can each be given a tenant.
 *
 * THE TEST THAT MATTERS MOST here is `test_two_pages_become_two_groups`. Grouping by channel alone
 * would produce a single "meta/messenger" bundle spanning two customers' Pages, and whichever org
 * resolved first would receive the other's conversations — a cross-tenant leak arising from a
 * perfectly legitimate delivery. The group key is (channel, resource_type, resource_id) precisely
 * so that cannot be expressed.
 */
class MetaEnvelopeClassifierTest extends TestCase
{
    use RefreshDatabase;

    private MetaEnvelopeClassifier $classifier;

    protected function setUp(): void
    {
        parent::setUp();
        $this->classifier = new MetaEnvelopeClassifier;
    }

    public function test_a_page_messaging_delivery_becomes_a_messenger_group(): void
    {
        $result = $this->classifier->classify(MetaEnvelopes::page(MetaEnvelopes::PAGE_A, [MetaEnvelopes::textItem()]));

        $this->assertCount(1, $result['groups']);
        $group = $result['groups'][0];

        $this->assertSame('messenger', $group->channel);
        $this->assertSame('page', $group->resourceType);
        $this->assertSame(MetaEnvelopes::PAGE_A, $group->resourceExternalId);
        $this->assertTrue($group->isMessaging());
        $this->assertSame(1, $group->count());
    }

    public function test_an_instagram_delivery_becomes_an_instagram_dm_group(): void
    {
        $result = $this->classifier->classify(MetaEnvelopes::instagram(MetaEnvelopes::IG_A, [MetaEnvelopes::igTextItem()]));

        $group = $result['groups'][0];

        $this->assertSame('instagram_dm', $group->channel);
        $this->assertSame('instagram_business_account', $group->resourceType);
        $this->assertSame(MetaEnvelopes::IG_A, $group->resourceExternalId);
    }

    public function test_a_leadgen_change_becomes_a_deferred_lead_ads_group(): void
    {
        $result = $this->classifier->classify(MetaEnvelopes::leadgen());

        $group = $result['groups'][0];

        $this->assertSame('lead_ads', $group->channel);
        $this->assertSame('page', $group->resourceType);
        $this->assertSame(MetaResourceGroup::KIND_LEADGEN, $group->kind);
        $this->assertFalse($group->isMessaging());
    }

    /** One Page, two products, two groups — they have different tenanting needs and different fates. */
    public function test_messenger_and_leadgen_on_one_page_become_separate_groups(): void
    {
        $result = $this->classifier->classify(MetaEnvelopes::messengerAndLeadgen());

        $this->assertCount(2, $result['groups']);

        $channels = array_map(fn (MetaResourceGroup $g): string => $g->channel, $result['groups']);
        sort($channels);

        $this->assertSame(['lead_ads', 'messenger'], $channels);
    }

    /** The cross-tenant case: one delivery, two Pages, two groups that can never merge. */
    public function test_two_pages_become_two_groups(): void
    {
        $result = $this->classifier->classify(MetaEnvelopes::twoPages());

        $this->assertCount(2, $result['groups']);

        $ids = array_map(fn (MetaResourceGroup $g): string => $g->resourceExternalId, $result['groups']);
        sort($ids);

        $this->assertSame([MetaEnvelopes::PAGE_A, MetaEnvelopes::PAGE_B], $ids);
        $this->assertNotSame($result['groups'][0]->key(), $result['groups'][1]->key());
    }

    /** Items for the same resource and channel are one group, in delivery order. */
    public function test_repeated_entries_for_one_resource_are_merged(): void
    {
        $envelope = ['object' => 'page', 'entry' => [
            ['id' => MetaEnvelopes::PAGE_A, 'messaging' => [MetaEnvelopes::textItem('mid.1', 'first')]],
            ['id' => MetaEnvelopes::PAGE_A, 'messaging' => [MetaEnvelopes::textItem('mid.2', 'second')]],
        ]];

        $result = $this->classifier->classify($envelope);

        $this->assertCount(1, $result['groups']);
        $this->assertSame(2, $result['groups'][0]->count());
    }

    public function test_an_unknown_object_is_refused_without_a_group(): void
    {
        $result = $this->classifier->classify(MetaEnvelopes::unknownObject());

        $this->assertSame([], $result['groups']);
        $this->assertSame(RejectionCode::UnsupportedChannel, $result['rejections'][0]->code);
    }

    public function test_a_malformed_envelope_is_refused(): void
    {
        foreach ([[], ['object' => 'page'], ['entry' => []], ['object' => ['page'], 'entry' => []]] as $envelope) {
            $result = $this->classifier->classify($envelope);

            $this->assertSame([], $result['groups']);
            $this->assertNotSame([], $result['rejections']);
        }
    }

    /** No resource id means no resource, and no resource means no tenant. */
    public function test_an_entry_without_an_id_is_refused(): void
    {
        $result = $this->classifier->classify(MetaEnvelopes::entryWithoutId());

        $this->assertSame([], $result['groups']);
        $this->assertSame(RejectionCode::MissingProviderIdentity, $result['rejections'][0]->code);
    }

    /** A malformed item costs itself; its valid siblings still classify. */
    public function test_a_malformed_item_does_not_discard_its_siblings(): void
    {
        $result = $this->classifier->classify(MetaEnvelopes::malformedSibling());

        $this->assertCount(1, $result['groups']);
        $this->assertSame(2, $result['groups'][0]->count(), 'both valid items survive');
        $this->assertCount(1, $result['rejections']);
    }

    /** The group hands the pipeline its OWN items, never the whole delivery. */
    public function test_a_group_envelope_contains_only_its_own_items(): void
    {
        $result = $this->classifier->classify(MetaEnvelopes::twoPages());

        foreach ($result['groups'] as $group) {
            $envelope = $group->toEnvelope();

            $this->assertArrayHasKey('items', $envelope);
            $this->assertCount(1, $envelope['items']);
            $this->assertArrayNotHasKey('entry', $envelope);
            $this->assertArrayNotHasKey('object', $envelope);

            // The resource identity travels with the items so the adapter can check each one is
            // addressed to the resource this group was resolved under.
            $this->assertSame($group->resourceExternalId, $envelope['resource_id']);
        }
    }
}
