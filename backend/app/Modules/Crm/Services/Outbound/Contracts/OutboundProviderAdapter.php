<?php

declare(strict_types=1);

namespace App\Modules\Crm\Services\Outbound\Contracts;

use App\Modules\Crm\Services\Outbound\Credentials\ProviderCredential;
use App\Modules\Crm\Services\Outbound\OutboundMessageRequest;
use App\Modules\Crm\Services\Outbound\OutboundMessageResult;
use App\Modules\Crm\Services\Outbound\OutboundResourceResolver;

/**
 * What one provider needs to implement before the CRM can speak through it.
 *
 * THE MIRROR OF THE INBOUND CONTRACT, NOT AN EXTENSION OF IT. Inbound adapters normalize somebody
 * else's payload into our vocabulary; outbound adapters render our vocabulary into somebody else's
 * API. The two share a provider name and nothing else — one is a parser that must never throw on
 * bad data, the other is a transport that must never be reached without a credential — so they get
 * separate interfaces rather than one interface with half its methods unused on each side.
 *
 * AN ADAPTER MAY NOT DECIDE TENANCY. It is handed a resolved resource and a credential and told to
 * use them. It does not read `crm_provider_accounts`, does not see an org id it could query with,
 * and cannot choose which Page to speak from — that decision belongs to
 * {@see OutboundResourceResolver}, against server-owned state.
 *
 * NO STATE BETWEEN CALLS, for the same reason the inbound adapters carry none: instances are
 * resolved once and reused across tenants.
 */
interface OutboundProviderAdapter
{
    /** A short, stable identifier for logs and for naming a duplicate-claim conflict. */
    public function name(): string;

    /** Whether this adapter owns a provider/channel pair. Exactly one adapter may claim any pair. */
    public function supports(string $provider, string $channel): bool;

    /**
     * The provider's own name for the kind of resource that speaks on this channel.
     *
     * Meta calls it `page` for Messenger and `instagram_business_account` for direct messages, and
     * those strings are Meta's vocabulary, not the CRM's — which is exactly why the mapping lives
     * behind the adapter instead of in a shared table that every future provider would have to be
     * bent to fit. Null means the adapter cannot speak on that channel.
     */
    public function resourceTypeFor(string $channel): ?string;

    /**
     * Whether this request is renderable at all, before any resource or credential work happens.
     *
     * Returns null when the request is fine, or a refusal when it is not. Cheap checks only —
     * emptiness, size, message type — because this runs before we have gone looking for a
     * credential, and a request that can never be sent should not cost a lookup.
     */
    public function validate(OutboundMessageRequest $request): ?OutboundMessageResult;

    /**
     * Hand the message to the provider.
     *
     * Reached only with a resolved resource and a real credential, which in Phase 3F.1 never
     * happens: the wired resolver has no credentials to give. An implementation that cannot yet
     * talk to its provider must refuse rather than pretend, and must never make an HTTP request.
     */
    public function send(
        OutboundMessageRequest $request,
        string $resourceExternalId,
        ProviderCredential $credential,
    ): OutboundMessageResult;
}
