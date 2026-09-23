<?php

declare(strict_types=1);

namespace App\Modules\Crm\Services\Meta\Connect\Assets;

/**
 * One Meta resource an authorized connection offers — a Page, or the Instagram account behind one.
 *
 * THE MINIMUM A PERSON NEEDS TO CHOOSE. An id to claim, a type to know what it is, a name to
 * recognise it by, and for a Page the Instagram account attached to it, because the two are
 * connected at Meta and a person choosing one almost always means both.
 *
 * NO TOKEN LIVES HERE, and that is the point of the class rather than an omission. Discovery hands
 * these to a UI so somebody can pick; a Page Access Token riding along would be rendered into a
 * JSON response the first time anyone serialized the list.
 */
final class MetaAsset
{
    public const PAGE = 'page';

    public const INSTAGRAM = 'instagram_business_account';

    public function __construct(
        /** Meta's own id. Claimed as `crm_provider_accounts.external_id`. */
        public readonly string $externalId,
        /** {@see self::PAGE} or {@see self::INSTAGRAM}. Matches `crm_provider_accounts.resource_type`. */
        public readonly string $type,
        /** What Meta calls it. Display only — never used to resolve anything. */
        public readonly string $displayName,
        /** For a Page, the Instagram business account attached to it, when there is one. */
        public readonly ?string $linkedInstagramId = null,
    ) {}

    /** Safe to serialize to a UI: identifiers and a name, nothing else. */
    public function toArray(): array
    {
        return [
            'externalId' => $this->externalId,
            'type' => $this->type,
            'displayName' => $this->displayName,
            'linkedInstagramId' => $this->linkedInstagramId,
        ];
    }
}
