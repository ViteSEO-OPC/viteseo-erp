<?php

declare(strict_types=1);

namespace App\Modules\Crm\Services\Inbound\Meta\Adapters;

/**
 * Facebook Messenger, claiming `meta/messenger` in the Phase 3C registry.
 *
 * Everything substantive lives in {@see AbstractMetaMessagingAdapter}; what is Messenger-specific is
 * only the channel it owns and the prefix on the synthesized thread id. The thread prefix is part of
 * the stored `external_conversation_id`, so it is effectively schema: changing it later would orphan
 * every existing thread rather than rename it.
 */
final class MetaMessengerAdapter extends AbstractMetaMessagingAdapter
{
    public function name(): string
    {
        return 'meta_messenger';
    }

    protected function channel(): string
    {
        return 'messenger';
    }

    protected function threadPrefix(): string
    {
        return 'fbpage';
    }
}
