<?php

declare(strict_types=1);

namespace App\Modules\Crm\Services\Outbound\Adapters;

use App\Modules\Crm\Services\Inbound\Adapters\InboundAdapterRegistry;
use App\Modules\Crm\Services\Outbound\Contracts\OutboundProviderAdapter;
use Illuminate\Contracts\Container\Container;

/**
 * Chooses the one adapter that may speak on a provider/channel pair.
 *
 * DELIBERATELY THE SAME SHAPE AS {@see InboundAdapterRegistry},
 * because the security property is the same one and it is worth being boring about: the candidate
 * classes come from `config('outbound.adapters')`, a list written in the repository, and a string
 * that arrived in a request is never turned into a class. No scanning, no `class_exists` on input.
 *
 * It is NOT the same object, though, and does not share a base class with the inbound registry.
 * They resolve different interfaces, and a shared parent would let a future change to one silently
 * alter which adapters the other considers — which for the outbound side means which integration a
 * customer's reply leaves through.
 *
 * DETERMINISM BY REFUSAL. Zero claims means the channel is not configured; more than one means the
 * config is wrong. Both throw, and both carry identifiers only.
 *
 * CONFIG IS READ PER RESOLUTION, not at construction, so a test can set `outbound.adapters` and get
 * the behaviour it configured. Instances are cached per class because they are stateless.
 */
final class OutboundAdapterRegistry
{
    /** @var array<class-string<OutboundProviderAdapter>, OutboundProviderAdapter> */
    private array $instances = [];

    public function __construct(private readonly Container $app) {}

    /**
     * The single adapter owning this pair.
     *
     * @throws UnsupportedOutboundChannelException when nothing claims it
     * @throws AmbiguousOutboundAdapterException when more than one does
     */
    public function for(string $provider, string $channel): OutboundProviderAdapter
    {
        $claims = [];

        foreach ($this->configured() as $class) {
            $adapter = $this->instance($class);

            if ($adapter->supports($provider, $channel)) {
                $claims[] = $adapter;
            }
        }

        if ($claims === []) {
            throw UnsupportedOutboundChannelException::for($provider, $channel);
        }

        if (count($claims) > 1) {
            throw AmbiguousOutboundAdapterException::between(
                $provider,
                $channel,
                array_map(static fn (OutboundProviderAdapter $a): string => $a->name(), $claims),
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
        } catch (OutboundAdapterException) {
            return false;
        }
    }

    /**
     * The configured adapter classes.
     *
     * @return list<class-string<OutboundProviderAdapter>>
     */
    private function configured(): array
    {
        $classes = [];

        foreach ((array) config('outbound.adapters', []) as $class) {
            if (is_string($class) && $class !== '') {
                $classes[] = $class;
            }
        }

        return $classes;
    }

    /** @param class-string<OutboundProviderAdapter> $class */
    private function instance(string $class): OutboundProviderAdapter
    {
        return $this->instances[$class] ??= $this->app->make($class);
    }
}
