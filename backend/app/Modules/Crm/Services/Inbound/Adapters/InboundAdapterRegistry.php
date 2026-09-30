<?php

declare(strict_types=1);

namespace App\Modules\Crm\Services\Inbound\Adapters;

use App\Modules\Crm\Services\Inbound\Contracts\InboundProviderAdapter;
use Illuminate\Contracts\Container\Container;

/**
 * Chooses the one adapter that owns a provider/channel pair.
 *
 * THE INPUT IS A PAIR OF STRINGS AND THE OUTPUT IS A CLASS, which is the whole security question
 * here. Those strings ultimately originate at a route and a provider classifier, so the rule is
 * absolute: a name from a request NEVER becomes a class to instantiate. The candidate classes come
 * from `config('inbound.adapters')` — a list written by us, in the repository — and the pair is
 * only ever compared against what those classes declare through `supports()`. No directory
 * scanning, no `class_exists` on request input, no discovery.
 *
 * DETERMINISM BY REFUSAL. Exactly one adapter must claim a pair. None means the provider is not
 * configured and nothing should be invented for it; more than one means the config is wrong and
 * picking a winner would hide it behind array order. Both are exceptions
 * ({@see UnsupportedProviderChannelException}, {@see AmbiguousAdapterException}), and both carry
 * identifiers only.
 *
 * CONFIG IS READ AT RESOLUTION, NOT AT CONSTRUCTION. The registry is a singleton, so reading the
 * list in the constructor would freeze whatever config held at boot — which is exactly the thing a
 * test needs to change, and the reason the mailbox manager grew an `extend()` hook. Reading it per
 * resolution removes the need for that hook entirely: a test sets `inbound.adapters` and gets the
 * behaviour it configured. Adapter INSTANCES are still cached per class, because they are
 * stateless and building one per delivery is waste.
 *
 * Production currently lists zero adapters — Phase 3C is the framework; the first real adapter
 * arrives with Phase 3D.
 */
final class InboundAdapterRegistry
{
    /** @var array<class-string<InboundProviderAdapter>, InboundProviderAdapter> */
    private array $instances = [];

    public function __construct(private readonly Container $app) {}

    /**
     * The single adapter owning this pair.
     *
     * @throws UnsupportedProviderChannelException when nothing claims it
     * @throws AmbiguousAdapterException when more than one does
     */
    public function for(string $provider, string $channel): InboundProviderAdapter
    {
        $claims = [];

        foreach ($this->configured() as $class) {
            $adapter = $this->instance($class);

            if ($adapter->supports($provider, $channel)) {
                $claims[] = $adapter;
            }
        }

        if ($claims === []) {
            throw UnsupportedProviderChannelException::for($provider, $channel);
        }

        if (count($claims) > 1) {
            throw AmbiguousAdapterException::between(
                $provider,
                $channel,
                array_map(static fn (InboundProviderAdapter $a): string => $a->name(), $claims),
            );
        }

        return $claims[0];
    }

    /** Whether a pair is servable, for callers that would rather branch than catch. */
    public function supports(string $provider, string $channel): bool
    {
        try {
            $this->for($provider, $channel);

            return true;
        } catch (InboundAdapterException) {
            return false;
        }
    }

    /**
     * The configured adapter classes.
     *
     * Duplicated entries are collapsed rather than reported as ambiguity: the same class listed
     * twice resolves to the same instance and is a typo in a config array, not two adapters
     * disagreeing about who owns a channel. Reporting it as a conflict would send someone looking
     * for a second implementation that does not exist.
     *
     * @return list<class-string<InboundProviderAdapter>>
     */
    private function configured(): array
    {
        $configured = config('inbound.adapters', []);

        if (! is_array($configured)) {
            throw new InboundAdapterException('inbound.adapters must be a list of adapter class names.');
        }

        $classes = [];

        foreach ($configured as $class) {
            if (! is_string($class) || $class === '') {
                throw new InboundAdapterException('inbound.adapters must contain adapter class names only.');
            }

            $classes[$class] = true;
        }

        /** @var list<class-string<InboundProviderAdapter>> */
        return array_keys($classes);
    }

    /**
     * Resolved through the container so an adapter may declare its own dependencies, and checked
     * after resolution: a class listed in config that is not an adapter is a deployment error, and
     * it must surface as that rather than as a fatal on a missing method mid-delivery.
     *
     * @param  class-string<InboundProviderAdapter>  $class
     */
    private function instance(string $class): InboundProviderAdapter
    {
        if (isset($this->instances[$class])) {
            return $this->instances[$class];
        }

        // `is_a` with the string form also answers false for a class that does not exist at all,
        // which is the other way a config list goes stale. Both are the same mistake to the person
        // fixing it: this entry cannot serve a delivery.
        if (! is_a($class, InboundProviderAdapter::class, true)) {
            throw new InboundAdapterException(sprintf(
                'Configured inbound adapter [%s] is missing or does not implement %s.',
                class_basename($class),
                class_basename(InboundProviderAdapter::class),
            ));
        }

        $adapter = $this->app->make($class);

        if (! $adapter instanceof InboundProviderAdapter) {
            throw new InboundAdapterException(sprintf(
                'Configured inbound adapter [%s] did not resolve to %s.',
                class_basename($class),
                class_basename(InboundProviderAdapter::class),
            ));
        }

        return $this->instances[$class] = $adapter;
    }
}
