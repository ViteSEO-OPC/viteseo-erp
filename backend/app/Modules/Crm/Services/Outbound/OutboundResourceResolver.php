<?php

declare(strict_types=1);

namespace App\Modules\Crm\Services\Outbound;

use App\Modules\Crm\Models\Conversation;
use App\Modules\Crm\Models\ProviderAccount;
use App\Modules\Crm\Services\Inbound\Meta\MetaAccountResolver;

/**
 * Decides which of the org's connected accounts a reply leaves through.
 *
 * WHY THIS IS THE HARDEST PART OF OUTBOUND. Inbound never has to choose: Meta tells us which Page
 * received the message, and {@see MetaAccountResolver} turns
 * that into a tenant. Replying reverses the question — we must pick a sender — and a wrong pick is
 * not a failed send but a message delivered to a real customer from the wrong business.
 *
 * TWO ROUTES, PREFERRED ORDER.
 *
 *  1. The thread's own history. An inbound row records `recipient_external_id`, which for Messenger
 *     is the Page that received the message and for Instagram the business account. That value was
 *     written by the signature-verified webhook path from a payload Meta signed — it is server-owned
 *     state, not request input — and it identifies the exact resource the customer spoke to. Using
 *     it means a reply always leaves from the account the conversation actually happened on.
 *
 *  2. The org's single connected resource of that type. Only when the thread carries no usable
 *     recipient, and only when the answer is unambiguous. One row: use it. Zero: refuse. More than
 *     one: refuse, because nothing in the data says which of the org's Pages this thread belongs to
 *     and guessing would be the wrong-business failure above.
 *
 * THE GLOBAL UNIQUE INDEX DOES NOT SAVE US HERE. `crm_provider_accounts` is unique on
 * (provider, resource_type, external_id), which stops two orgs claiming one Page but says nothing
 * about one org owning five. Route 2's ambiguity check is therefore load-bearing for every SaaS
 * tenant beyond the first, and Phase 3F.2 should remove the need for it by recording the resource
 * on the conversation at ingest time — see the phase report.
 *
 * THE ORG PREDICATE ON THE ACCOUNT QUERY IS THE ONLY TENANT FILTER THERE IS. {@see ProviderAccount}
 * deliberately does NOT use `BelongsToOrg` — it is the table consulted to DISCOVER a tenant on the
 * public webhook path, before any org is pinned, so scoping it to the current org would make that
 * lookup circular. Every other CRM model gets a second line of defence from the global scope; this
 * one does not, which makes the explicit `where('org_id', …)` below load-bearing rather than
 * belt-and-braces. It is written from the conversation's own column, never from a caller.
 *
 * THE MESSAGE QUERY DROPS ITS GLOBAL SCOPE ON PURPOSE and replaces it with the same explicit
 * predicate. `CurrentOrg::id()` is null outside an HTTP request and outbound is inherently
 * queueable, so leaving the scope on would make this return nothing in a job — failing closed, but
 * for a reason that has nothing to do with the tenant and would be debugged as a data problem.
 */
final class OutboundResourceResolver
{
    /**
     * The account this conversation should speak from, or a refusal explaining why not.
     *
     * @return ResolvedProviderResource|OutboundFailureCode the resource, or the reason there is none
     */
    public function resolve(
        Conversation $conversation,
        string $resourceType,
    ): ResolvedProviderResource|OutboundFailureCode {
        $orgId = (int) $conversation->org_id;
        $provider = (string) $conversation->provider;

        $recipient = $this->recipientFromThread($conversation);

        if ($recipient !== null) {
            $account = ProviderAccount::query()
                ->where('org_id', $orgId)
                ->where('provider', $provider)
                ->where('resource_type', $resourceType)
                ->where('external_id', $recipient)
                ->where('is_active', true)
                ->first();

            // A recipient the thread names but the org does not own is not a reason to fall back to
            // some other Page — it means the mapping was removed or moved to another tenant.
            return $account === null
                ? OutboundFailureCode::ResourceUnmapped
                : $this->toResource($account);
        }

        $candidates = ProviderAccount::query()
            ->where('org_id', $orgId)
            ->where('provider', $provider)
            ->where('resource_type', $resourceType)
            ->where('is_active', true)
            ->orderBy('id')
            ->get();

        return match (true) {
            $candidates->isEmpty() => OutboundFailureCode::ResourceUnmapped,
            $candidates->count() > 1 => OutboundFailureCode::ResourceAmbiguous,
            default => $this->toResource($candidates->first()),
        };
    }

    /**
     * The resource this thread was addressed to, from its most recent inbound turn.
     *
     * Most recent rather than first: a Page can be migrated, and the latest inbound row is the best
     * evidence of where the customer is speaking now. Outbound rows are excluded because their
     * recipient is the customer, not us.
     */
    private function recipientFromThread(Conversation $conversation): ?string
    {
        $value = $conversation->messages()
            ->withoutGlobalScopes()
            ->reorder()
            ->where('org_id', (int) $conversation->org_id)
            ->where('direction', 'inbound')
            ->whereNotNull('recipient_external_id')
            ->orderByDesc('id')
            ->value('recipient_external_id');

        $value = is_string($value) ? trim($value) : '';

        return $value === '' ? null : $value;
    }

    private function toResource(ProviderAccount $account): ResolvedProviderResource
    {
        return new ResolvedProviderResource(
            providerAccountId: (int) $account->id,
            orgId: (int) $account->org_id,
            provider: (string) $account->provider,
            resourceType: (string) $account->resource_type,
            externalId: (string) $account->external_id,
        );
    }
}
