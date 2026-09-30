<?php

declare(strict_types=1);

namespace App\Modules\Crm\Services\Meta\Connect;

use App\Modules\Crm\Services\Meta\Connect\Assets\MetaAsset;

/**
 * The Pages and Instagram accounts a grant covers, or the reason none could be listed.
 *
 * @see MetaExchangeResult for why a failure is a value rather than an exception
 */
final class MetaAssetDiscovery
{
    /** @param list<MetaAsset> $assets */
    private function __construct(
        public readonly bool $successful,
        public readonly array $assets = [],
        public readonly ?MetaConnectFailureCode $failure = null,
    ) {}

    /** @param list<MetaAsset> $assets */
    public static function success(array $assets): self
    {
        return new self(true, assets: $assets);
    }

    public static function failed(MetaConnectFailureCode $code): self
    {
        return new self(false, failure: $code);
    }

    /** Identifiers only — safe for a log line or a diagnostic. */
    public function externalIds(): array
    {
        return array_map(static fn (MetaAsset $a): string => $a->externalId, $this->assets);
    }
}
