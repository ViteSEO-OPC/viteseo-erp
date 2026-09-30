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

    /**
     * Instagram's Send API body.
     *
     * NO `messaging_type`. Instagram's messaging API does not take the field that Messenger
     * requires, and sending it is a request Meta rejects — which is the concrete reason these two
     * adapters are separate classes rather than one with a branch on channel. The credential is
     * still the linked Page's token: that is Meta's model, and it arrives here already resolved by
     * the database credential resolver, so this class adds no second credential mechanism.
     *
     * @return array<string, mixed>
     */
    protected function payloadFor(string $recipientExternalId, string $body): array
    {
        return [
            'recipient' => ['id' => $recipientExternalId],
            'message' => ['text' => $body],
        ];
    }

    /**
     * `me`, which a Page access token resolves to the Page that owns this Instagram account.
     *
     * NOT the Instagram business account id. That was the defect: with Instagram API for Facebook
     * Login the messaging graph belongs to the PAGE, and POSTing to /{ig-business-account-id}/messages
     * is refused with Meta error code 3 — verified in production, and reproduced read-only by
     * GET /{ig-business-account-id}/conversations returning the same code while
     * GET /{page-id}/conversations?platform=instagram returns the thread.
     *
     * Using `me` keeps this adapter out of the tenancy business entirely: it performs no lookup,
     * reads no table, and cannot address a Page other than the one its credential authorises. The
     * resolved resource is still the Instagram business account — that is what owns tenant
     * selection and the credential — and it is deliberately ignored here, which is why the
     * parameter is unused.
     */
    protected function endpointNode(string $resourceExternalId): string
    {
        unset($resourceExternalId);

        return 'me';
    }
}
