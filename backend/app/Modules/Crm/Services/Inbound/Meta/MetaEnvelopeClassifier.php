<?php

declare(strict_types=1);

namespace App\Modules\Crm\Services\Inbound\Meta;

use App\Modules\Crm\Services\Inbound\Adapters\AdapterRejection;
use App\Modules\Crm\Services\Inbound\Adapters\RejectionCode;

/**
 * The one place that knows what a Meta webhook envelope looks like.
 *
 * Everything below this class is Meta-shaped; everything above it is not. The Phase 3C registry and
 * pipeline never see `object`, `entry`, `messaging` or `changes` — they receive a provider name, a
 * channel and a bag of items, which is exactly why adding Meta needed no change to them.
 *
 * WHAT IT PRODUCES. A list of {@see MetaResourceGroup}, each one channel on one Meta resource (see
 * that class for why the resource is part of the identity). A single delivery legitimately fans out:
 * `object: page` can carry Messenger messaging AND a leadgen change, for two different Pages, in one
 * signed request. Splitting it here is what lets each piece be resolved to its own tenant.
 *
 * WHAT IT REFUSES TO GUESS. An unknown `object`, an entry with no id, an item that is not an array —
 * each becomes a rejection with a code and no CRM record. Inventing a resource id for an entry that
 * has none would be inventing a tenant.
 *
 * ONE MALFORMED SIBLING COSTS ITSELF. A bad entry or item is recorded and skipped; the rest of the
 * delivery is still classified. The exception is an envelope-level violation (size, depth, fan-out),
 * which is decided before this class runs.
 */
final class MetaEnvelopeClassifier
{
    /** Meta's `object` values we recognise, mapped to the channel they imply for messaging. */
    private const MESSAGING_OBJECTS = [
        'page' => 'messenger',
        'instagram' => 'instagram_dm',
    ];

    /**
     * @param  array<string, mixed>  $envelope  the decoded, already-signature-verified delivery
     * @return array{groups: list<MetaResourceGroup>, rejections: list<AdapterRejection>}
     */
    public function classify(array $envelope): array
    {
        $object = $envelope['object'] ?? null;
        $entries = $envelope['entry'] ?? null;

        if (! is_string($object) || ! is_array($entries)) {
            return ['groups' => [], 'rejections' => [new AdapterRejection(RejectionCode::MalformedEvent)]];
        }

        if (! array_key_exists($object, self::MESSAGING_OBJECTS)) {
            // A subscription we never asked for, or a new Meta product. Recognised as "not ours"
            // rather than parsed hopefully.
            return ['groups' => [], 'rejections' => [new AdapterRejection(RejectionCode::UnsupportedChannel)]];
        }

        $buckets = [];
        $rejections = [];

        foreach (array_values($entries) as $index => $entry) {
            if (! is_array($entry)) {
                $rejections[] = new AdapterRejection(RejectionCode::MalformedEvent, $index);

                continue;
            }

            $entryId = $this->scalarString($entry['id'] ?? null);

            if ($entryId === null) {
                // No resource id means no resource, and no resource means no tenant.
                $rejections[] = new AdapterRejection(RejectionCode::MissingProviderIdentity, $index);

                continue;
            }

            $this->collectMessaging($object, $entryId, $entry, $index, $buckets, $rejections);
            $this->collectLeadgen($object, $entryId, $entry, $index, $buckets, $rejections);
        }

        return ['groups' => array_values($buckets), 'rejections' => $rejections];
    }

    /**
     * @param  array<string, MetaResourceGroup>  $buckets
     * @param  list<AdapterRejection>  $rejections
     */
    private function collectMessaging(
        string $object,
        string $entryId,
        array $entry,
        int $index,
        array &$buckets,
        array &$rejections,
    ): void {
        $items = $entry['messaging'] ?? null;

        if (! is_array($items)) {
            return;
        }

        $channel = self::MESSAGING_OBJECTS[$object];
        $resourceType = $object === 'instagram'
            ? (string) config('meta.resource_types.instagram', 'instagram_business_account')
            : (string) config('meta.resource_types.page', 'page');

        $collected = [];

        foreach (array_values($items) as $item) {
            if (! is_array($item)) {
                $rejections[] = new AdapterRejection(RejectionCode::MalformedEvent, $index);

                continue;
            }

            $collected[] = $item;
        }

        if ($collected === []) {
            return;
        }

        $this->push($buckets, new MetaResourceGroup(
            MetaResourceGroup::KIND_MESSAGING,
            $channel,
            $resourceType,
            $entryId,
            $collected,
        ));
    }

    /**
     * Leadgen arrives as a `changes[]` entry on a Page, not as messaging. It is grouped like
     * anything else so the Page still resolves to exactly one tenant before any decision is made.
     *
     * @param  array<string, MetaResourceGroup>  $buckets
     * @param  list<AdapterRejection>  $rejections
     */
    private function collectLeadgen(
        string $object,
        string $entryId,
        array $entry,
        int $index,
        array &$buckets,
        array &$rejections,
    ): void {
        $changes = $entry['changes'] ?? null;

        if ($object !== 'page' || ! is_array($changes)) {
            return;
        }

        foreach (array_values($changes) as $change) {
            if (! is_array($change) || ($change['field'] ?? null) !== 'leadgen') {
                continue;
            }

            $value = $change['value'] ?? null;

            if (! is_array($value)) {
                $rejections[] = new AdapterRejection(RejectionCode::MalformedEvent, $index);

                continue;
            }

            // The Page the form belongs to. The change's own page_id is preferred over the entry id
            // because it is the identity Meta states for the form; both are validated as scalars.
            $pageId = $this->scalarString($value['page_id'] ?? null) ?? $entryId;

            $this->push($buckets, new MetaResourceGroup(
                MetaResourceGroup::KIND_LEADGEN,
                'lead_ads',
                (string) config('meta.resource_types.page', 'page'),
                $pageId,
                [$value],
            ));
        }
    }

    /** @param  array<string, MetaResourceGroup>  $buckets */
    private function push(array &$buckets, MetaResourceGroup $group): void
    {
        $key = $group->key();

        if (! isset($buckets[$key])) {
            $buckets[$key] = $group;

            return;
        }

        // Same channel, same resource: one group, items appended in delivery order.
        $buckets[$key] = $buckets[$key]->withItems([...$buckets[$key]->items, ...$group->items]);
    }

    /** Meta sends ids as strings or integers; anything else is not an id. */
    private function scalarString(mixed $value): ?string
    {
        if (is_string($value)) {
            $trimmed = trim($value);

            return $trimmed === '' ? null : $trimmed;
        }

        if (is_int($value)) {
            return (string) $value;
        }

        return null;
    }
}
