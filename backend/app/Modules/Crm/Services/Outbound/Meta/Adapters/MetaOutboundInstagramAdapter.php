<?php

declare(strict_types=1);

namespace App\Modules\Crm\Services\Outbound\Meta\Adapters;

/**
 * Replies on an Instagram direct thread.
 *
 * A separate class from the Messenger adapter rather than one adapter with a branch, because the
 * two diverge the moment a transport exists — Instagram's send window, its attachment rules and
 * its error vocabulary are its own — and because the registry's one-adapter-per-pair rule is only
 * meaningful if each pair has a class that can be pointed at.
 */
final class MetaOutboundInstagramAdapter extends AbstractMetaOutboundAdapter
{
    public function name(): string
    {
        return 'meta-instagram-outbound';
    }

    protected function channel(): string
    {
        return 'instagram_dm';
    }

    protected function resourceType(): string
    {
        return 'instagram_business_account';
    }
}
