<?php

declare(strict_types=1);

namespace Tests\Feature\Crm;

use App\Modules\Crm\Models\Activity;
use App\Modules\Crm\Models\Conversation;
use App\Modules\Crm\Models\ConversationMessage;
use App\Modules\Crm\Models\Lead;
use App\Modules\Crm\Services\Inbound\Adapters\InboundAdapterRegistry;
use App\Modules\Crm\Services\Inbound\Adapters\UnsupportedProviderChannelException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\Inbound\CapturesLogFile;
use Tests\Support\Inbound\Meta\MetaEnvelopes;
use Tests\Support\Inbound\Meta\SignsMetaDeliveries;
use Tests\TestCase;

/**
 * Lead Ads: recognised, resolved to a tenant, and then deliberately left alone.
 *
 * WHY NOTHING IS WRITTEN. A leadgen webhook carries identifiers only — `leadgen_id`, `form_id`,
 * `page_id` — and the answers the person actually typed sit behind a Graph API call this phase does
 * not make. So there is no conversation turn to record. Writing one anyway would put an empty
 * "message" in a customer's thread and invite whoever reads it later to infer intent that was never
 * captured; creating a lead from it would break the standing rule that inbound never creates leads.
 *
 * Recognised and deferred is therefore the honest outcome, and these tests pin the "nothing
 * happened" half as carefully as the recognition half — including that no adapter is registered for
 * the channel at all.
 */
class MetaLeadgenDeferredTest extends TestCase
{
    use CapturesLogFile;
    use RefreshDatabase;
    use SignsMetaDeliveries;

    protected function setUp(): void
    {
        parent::setUp();
        $this->enableMetaWebhook();
        $this->connectMetaResource($this->tenant()->id, MetaEnvelopes::PAGE_A);
    }

    private function assertNothingPersisted(): void
    {
        $this->assertSame(0, ConversationMessage::count(), 'no CRM message');
        $this->assertSame(0, Conversation::count(), 'no conversation');
        $this->assertSame(0, Lead::count(), 'and above all, no lead');
    }

    public function test_a_valid_leadgen_notification_is_acknowledged_and_persists_nothing(): void
    {
        $this->postSigned(MetaEnvelopes::leadgen())->assertOk();

        $this->assertNothingPersisted();
    }

    /** It is DEFERRED, not rejected — the distinction the counts have to preserve. */
    public function test_the_outcome_is_deferred_rather_than_rejected(): void
    {
        $log = $this->captureLog(fn () => $this->postSigned(MetaEnvelopes::leadgen()));

        $this->assertStringContainsString('crm.inbound.meta.deferred', $log);
        $this->assertStringContainsString('deferred_leadgen', $log);
        $this->assertStringContainsString('"deferred_leadgen_count":1', $log);
        $this->assertStringContainsString('"rejected_count":0', $log);
    }

    /** The Page still has to resolve: an unmapped one is not silently deferred either. */
    public function test_the_page_mapping_is_still_resolved(): void
    {
        $log = $this->captureLog(fn () => $this->postSigned(MetaEnvelopes::leadgen(MetaEnvelopes::PAGE_B)));

        $this->assertStringContainsString('unknown_resource_mapping', $log);
        $this->assertStringNotContainsString('crm.inbound.meta.deferred', $log);
        $this->assertStringContainsString('"deferred_leadgen_count":0', $log);
        $this->assertStringContainsString('"unknown_mapping_count":1', $log);
        $this->assertNothingPersisted();
    }

    /** Safe identifiers only — and the whole point is there is no submitted PII to leak yet. */
    public function test_only_safe_identifiers_are_logged(): void
    {
        $log = $this->captureLog(fn () => $this->postSigned(MetaEnvelopes::leadgen(MetaEnvelopes::PAGE_A, 'lead_77', 'form_88')));

        $this->assertStringContainsString('lead_77', $log, 'the leadgen id is a safe identifier');
        $this->assertStringContainsString('form_88', $log);
        $this->assertStringNotContainsString('created_time', $log, 'no raw value bag');
        $this->assertStringNotContainsString('"object":"page"', $log, 'no raw body');
    }

    public function test_a_malformed_leadgen_is_recorded_and_still_persists_nothing(): void
    {
        $log = $this->captureLog(fn () => $this->postSigned(MetaEnvelopes::malformedLeadgen())->assertOk());

        $this->assertStringContainsString('malformed_leadgen', $log);
        $this->assertNothingPersisted();
    }

    /** A redelivery creates nothing, because nothing was created the first time. */
    public function test_a_duplicate_notification_still_creates_nothing(): void
    {
        $this->postSigned(MetaEnvelopes::leadgen())->assertOk();
        $this->postSigned(MetaEnvelopes::leadgen())->assertOk();

        $this->assertNothingPersisted();
        $this->assertSame(0, Activity::count(), 'no timeline entry either');
    }

    /** Messenger beside leadgen in one delivery: the message lands, the notification does not. */
    public function test_a_mixed_delivery_ingests_the_message_and_defers_the_notification(): void
    {
        $this->postSigned(MetaEnvelopes::messengerAndLeadgen())->assertOk();

        $this->assertSame(1, ConversationMessage::count(), 'the Messenger turn');
        $this->assertSame(1, Conversation::count());
        $this->assertSame('messenger', ConversationMessage::firstOrFail()->channel);
        $this->assertSame(0, Lead::count());
    }

    /** No adapter claims lead_ads, so the channel cannot be normalized even by mistake. */
    public function test_no_adapter_is_registered_for_lead_ads(): void
    {
        $this->expectException(UnsupportedProviderChannelException::class);

        $this->app->make(InboundAdapterRegistry::class)->for('meta', 'lead_ads');
    }

    /** And the production config lists only the two messaging adapters. */
    public function test_production_config_registers_no_lead_ads_adapter(): void
    {
        foreach ((array) config('inbound.adapters') as $class) {
            $this->assertStringNotContainsString('LeadAds', (string) $class);
        }

        $this->assertCount(2, (array) config('inbound.adapters'));
    }
}
