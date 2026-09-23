<?php

declare(strict_types=1);

namespace App\Modules\Crm\Services\Outbound\Meta\Adapters;

/** Replies on a Facebook Page thread. The sender is always the Page the conversation arrived on. */
final class MetaOutboundMessengerAdapter extends AbstractMetaOutboundAdapter
{
    public function name(): string
    {
        return 'meta-messenger-outbound';
    }

    protected function channel(): string
    {
        return 'messenger';
    }

    protected function resourceType(): string
    {
        return 'page';
    }
}
