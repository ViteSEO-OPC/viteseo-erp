<?php

declare(strict_types=1);

namespace Tests\Feature\Crm;

use App\Modules\Crm\Services\Inbound\Adapters\AmbiguousAdapterException;
use App\Modules\Crm\Services\Inbound\Adapters\InboundAdapterException;
use App\Modules\Crm\Services\Inbound\Adapters\InboundAdapterRegistry;
use App\Modules\Crm\Services\Inbound\Adapters\UnsupportedProviderChannelException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use stdClass;
use Tests\Support\Inbound\DuplicateFixtureInboundProviderAdapter;
use Tests\Support\Inbound\FixtureInboundProviderAdapter;
use Tests\Support\Inbound\OtherChannelFixtureAdapter;
use Tests\TestCase;

/**
 * Which adapter runs, and — more importantly — when none does.
 *
 * The registry is the only place in Phase 3C where a string chooses a class, so the behaviour
 * worth pinning is its refusals: an unconfigured pair must produce nothing rather than a guess,
 * and two adapters claiming one pair must produce an error rather than whichever the config
 * happened to list first.
 */
class InboundAdapterRegistryTest extends TestCase
{
    use RefreshDatabase;

    private function registry(array $adapters): InboundAdapterRegistry
    {
        config(['inbound.adapters' => $adapters]);

        return $this->app->make(InboundAdapterRegistry::class);
    }

    /** 1 — exactly one adapter resolves, proven against a registry that holds several. */
    public function test_exactly_one_adapter_resolves_a_provider_channel_pair(): void
    {
        $registry = $this->registry([
            FixtureInboundProviderAdapter::class,
            OtherChannelFixtureAdapter::class,
        ]);

        $chat = $registry->for('fixture', 'chat');
        $voice = $registry->for('fixture', 'voice');

        $this->assertInstanceOf(FixtureInboundProviderAdapter::class, $chat);
        $this->assertSame('fixture', $chat->name());
        $this->assertInstanceOf(OtherChannelFixtureAdapter::class, $voice);
        $this->assertSame('fixture_voice', $voice->name());
    }

    /** Resolution is cached but not confused: the same pair returns the same instance. */
    public function test_resolution_is_stable_across_calls(): void
    {
        $registry = $this->registry([FixtureInboundProviderAdapter::class]);

        $this->assertSame($registry->for('fixture', 'chat'), $registry->for('fixture', 'chat'));
    }

    /** 2 — an unconfigured pair fails closed, and says only which pair. */
    public function test_an_unsupported_provider_channel_pair_fails_closed(): void
    {
        $registry = $this->registry([FixtureInboundProviderAdapter::class]);

        try {
            $registry->for('meta', 'messenger');
            $this->fail('an unregistered pair must not resolve');
        } catch (UnsupportedProviderChannelException $e) {
            $this->assertStringContainsString('meta/messenger', $e->getMessage());
            $this->assertStringNotContainsString('Fixture', $e->getMessage());
        }

        $this->assertFalse($registry->supports('meta', 'messenger'));
        $this->assertTrue($registry->supports('fixture', 'chat'));
    }

    /** The provider may be known while the channel is not — both halves are part of identity. */
    public function test_a_known_provider_with_an_unknown_channel_fails_closed(): void
    {
        $registry = $this->registry([FixtureInboundProviderAdapter::class]);

        $this->expectException(UnsupportedProviderChannelException::class);

        $registry->for('fixture', 'lead_ads');
    }

    /** 3 — two adapters claiming one pair is a configuration error, never a race to be first. */
    public function test_overlapping_adapter_claims_fail_closed(): void
    {
        $registry = $this->registry([
            FixtureInboundProviderAdapter::class,
            DuplicateFixtureInboundProviderAdapter::class,
        ]);

        try {
            $registry->for('fixture', 'chat');
            $this->fail('an ambiguous claim must not resolve');
        } catch (AmbiguousAdapterException $e) {
            $this->assertStringContainsString('fixture', $e->getMessage());
            $this->assertStringContainsString('fixture_duplicate', $e->getMessage());
        }
    }

    /** Listing the same adapter twice is a typo, not a conflict — it still resolves. */
    public function test_a_duplicated_config_entry_is_not_treated_as_a_conflict(): void
    {
        $registry = $this->registry([
            FixtureInboundProviderAdapter::class,
            FixtureInboundProviderAdapter::class,
        ]);

        $this->assertSame('fixture', $registry->for('fixture', 'chat')->name());
    }

    /** 4 — the adapter name is safe to log and stable across resolutions. */
    public function test_the_adapter_name_is_safe_and_stable(): void
    {
        $registry = $this->registry([FixtureInboundProviderAdapter::class]);

        $name = $registry->for('fixture', 'chat')->name();

        $this->assertMatchesRegularExpression('/^[a-z0-9][a-z0-9_.-]*$/', $name);
        $this->assertSame($name, $registry->for('fixture', 'chat')->name());
    }

    /** A config entry that is not an adapter is a deployment error, surfaced as one. */
    public function test_a_configured_class_that_is_not_an_adapter_is_refused(): void
    {
        $registry = $this->registry([stdClass::class]);

        $this->expectException(InboundAdapterException::class);

        $registry->for('fixture', 'chat');
    }

    /** As is a class that no longer exists. */
    public function test_a_missing_adapter_class_is_refused(): void
    {
        $registry = $this->registry(['App\\Modules\\Crm\\Services\\Inbound\\Adapters\\GoneAway']);

        $this->expectException(InboundAdapterException::class);

        $registry->for('fixture', 'chat');
    }

    /** A malformed config value must not be interpreted generously. */
    public function test_a_malformed_adapter_config_is_refused(): void
    {
        $registry = $this->registry([['not' => 'a class name']]);

        $this->expectException(InboundAdapterException::class);

        $registry->for('fixture', 'chat');
    }
}
