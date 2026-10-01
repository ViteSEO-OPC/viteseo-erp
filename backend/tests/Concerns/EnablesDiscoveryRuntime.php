<?php

namespace Tests\Concerns;

/**
 * For suites that exercise discovery AS DEPLOYED WITH ITS RUNTIME PRESENT: a dedicated `discovery`
 * worker (DISCOVERY_WORKER_ENABLED) and the enrichment tick switched on (DISCOVERY_ENRICHMENT_ENABLED).
 *
 * Both default OFF, and that default is pinned by DiscoveryRuntimeSwitchesTest. These suites test what
 * happens once an operator has turned them on, so they opt in explicitly per class rather than through
 * phpunit.xml, which would hide the default from every other test.
 *
 * Runs after the application boots (Laravel calls setUp<TraitName> for each trait), which is early
 * enough: both switches are read when a request or command runs, never when the app is built.
 */
trait EnablesDiscoveryRuntime
{
    protected function setUpEnablesDiscoveryRuntime(): void
    {
        config()->set('discovery.worker_enabled', true);
        config()->set('discovery.enrichment.enabled', true);
    }
}
