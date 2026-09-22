<?php

declare(strict_types=1);

namespace App\Modules\Crm\Services\Inbound\Meta\Adapters;

/**
 * Instagram Direct Messages, claiming `meta/instagram_dm` in the Phase 3C registry.
 *
 * Separate from Messenger despite the shared grammar, because the registry resolves one adapter per
 * (provider, channel) pair and refuses ambiguity: two channels behind one adapter would mean one
 * `normalizeEnvelope()` carrying two payload dialects. The identities here are IGSIDs and an
 * Instagram business account rather than PSIDs and a Page, which is why the thread prefix differs —
 * the same numeric id space is not shared between the two products.
 */
final class MetaInstagramAdapter extends AbstractMetaMessagingAdapter
{
    public function name(): string
    {
        return 'meta_instagram';
    }

    protected function channel(): string
    {
        return 'instagram_dm';
    }

    protected function threadPrefix(): string
    {
        return 'igaccount';
    }
}
