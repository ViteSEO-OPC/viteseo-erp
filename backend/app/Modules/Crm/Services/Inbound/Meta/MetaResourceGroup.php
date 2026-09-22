<?php

declare(strict_types=1);

namespace App\Modules\Crm\Services\Inbound\Meta;

/**
 * One Meta delivery, cut down to a single channel on a single Meta resource.
 *
 * WHY THE RESOURCE IS PART OF THE IDENTITY AND NOT JUST THE CHANNEL. A signed delivery may carry
 * `entry[]` items for several Pages at once, and each Page can belong to a DIFFERENT CRM tenant.
 * Grouping by channel alone would produce one "meta/messenger" bundle spanning two customers, and
 * whichever org resolved first would receive the other's conversations — a cross-tenant leak from
 * a delivery that was perfectly legitimate. The group key is therefore
 * (channel, resource_type, resource_external_id), so a group is by construction the largest unit
 * that can safely share one tenant context.
 *
 * `kind` separates what may be ingested from what may only be acknowledged: MESSAGING flows through
 * the Phase 3C pipeline, LEADGEN is recognised and deferred (a leadgen notification carries no
 * conversation turn and no submitted answers, so there is nothing faithful to persist yet).
 *
 * Immutable, and it holds provider items — so it is data, never instructions.
 */
final class MetaResourceGroup
{
    public const KIND_MESSAGING = 'messaging';

    public const KIND_LEADGEN = 'leadgen';

    /**
     * @param  string  $channel  messenger | instagram_dm | lead_ads
     * @param  string  $resourceType  page | instagram_business_account
     * @param  list<array<string, mixed>>  $items  the individual provider items, each already its own slice
     */
    public function __construct(
        public readonly string $kind,
        public readonly string $channel,
        public readonly string $resourceType,
        public readonly string $resourceExternalId,
        public readonly array $items,
    ) {}

    /** The grouping key. Identity is the triple, never the channel alone. */
    public function key(): string
    {
        return $this->channel.'|'.$this->resourceType.'|'.$this->resourceExternalId;
    }

    public function isMessaging(): bool
    {
        return $this->kind === self::KIND_MESSAGING;
    }

    public function count(): int
    {
        return count($this->items);
    }

    /**
     * What the Phase 3C pipeline receives: the group's own items, never the whole delivery.
     *
     * The resource id travels with them so the adapter can check that each item is actually
     * addressed to the resource this group was resolved under. Without it the two identities drift:
     * the tenant is decided from the entry, while the thread would be keyed by whatever the item
     * names as its recipient, and a turn could be stored under one Page's tenant while carrying
     * another Page's thread id.
     */
    public function toEnvelope(): array
    {
        return ['resource_id' => $this->resourceExternalId, 'items' => $this->items];
    }

    public function withItems(array $items): self
    {
        return new self($this->kind, $this->channel, $this->resourceType, $this->resourceExternalId, array_values($items));
    }
}
