<?php

declare(strict_types=1);

namespace App\Modules\Crm\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Crm\Models\Conversation;
use App\Modules\Crm\Models\ConversationMessage;
use App\Modules\Crm\Requests\ConversationIndexRequest;
use App\Modules\Crm\Resources\ConversationDetailResource;
use App\Modules\Crm\Resources\ConversationResource;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Collection;

/**
 * The read-only conversation inbox — the first place inbound Messenger and Instagram messages
 * become visible to a human.
 *
 * READ-ONLY BY CONSTRUCTION, not by convention: this controller has two methods and neither writes.
 * There is no reply, no send, no status change, no lead linking and no Meta API call anywhere in
 * the module, because none of that is in scope and a half-built send path is worse than none.
 *
 * TENANCY IS NOT IMPLEMENTED HERE, WHICH IS THE POINT. Conversation and ConversationMessage both
 * use BelongsToOrg, whose global scope appends `where(org_id, CurrentOrg::id())` to every query the
 * model builds — including the implicit route-model binding below. So a request for another
 * tenant's conversation id does not return a filtered result, it returns a 404: the row is not
 * merely hidden, it is unreachable. Re-implementing the filter here would add a second place for it
 * to be forgotten.
 */
class ConversationController extends Controller
{
    /** A thread longer than this is trimmed to its most recent turns; see show(). */
    private const MAX_THREAD = 200;

    public function index(ConversationIndexRequest $request): AnonymousResourceCollection
    {
        $filters = $request->validated();

        $conversations = Conversation::query()
            ->when($filters['channel'] ?? null, fn (Builder $q, string $c) => $q->where('channel', $c))
            ->when($filters['provider'] ?? null, fn (Builder $q, string $p) => $q->where('provider', $p))
            ->when($filters['status'] ?? null, fn (Builder $q, string $s) => $q->where('status', $s))
            ->when(($filters['lead'] ?? null) === 'linked', fn (Builder $q) => $q->whereNotNull('lead_id'))
            ->when(($filters['lead'] ?? null) === 'unlinked', fn (Builder $q) => $q->whereNull('lead_id'))
            // The lead is eager-loaded because the resource asks LeadPolicy about it; without this
            // the authorization check itself would be the N+1.
            ->with('lead')
            // Newest activity first — a thread that just received a message is the one a person
            // wants at the top. Falls back to id for rows that somehow have no last_message_at.
            ->orderByDesc('last_message_at')
            ->orderByDesc('id')
            ->paginate((int) ($filters['per_page'] ?? 15))
            ->withQueryString();

        $this->attachLatestMessages($conversations->getCollection());

        return ConversationResource::collection($conversations);
    }

    public function show(Conversation $conversation): ConversationDetailResource
    {
        /*
         * `$conversation` arrived through route-model binding, which resolved it through the
         * BelongsToOrg scope — another tenant's id is already a 404 before this line runs.
         *
         * The thread is bounded: newest MAX_THREAD turns, then reversed so the UI reads oldest
         * first. An unbounded read would be fine today, with two messages in production, and would
         * quietly become a problem on the first long-running thread.
         */
        /*
         * `reorder()` is required, not decorative. The Phase 3A relation already carries
         * `ORDER BY COALESCE(occurred_at, received_at, created_at) ASC` — a better ordering than a
         * bare occurred_at, because a provider that sends no timestamp still lands in sequence.
         * Appending a DESC clause would NOT override it: the relation's ASC comes first and wins,
         * so the limit would have taken the OLDEST turns and the reverse would have inverted the
         * thread. Clearing the order and restating the same expression descending takes the newest
         * turns, and the reverse then restores reading order.
         */
        $messages = $conversation->messages()
            ->reorder()
            ->orderByRaw('COALESCE(occurred_at, received_at, created_at) DESC')
            ->orderByDesc('id')
            ->limit(self::MAX_THREAD)
            ->get()
            ->reverse()
            ->values();

        $conversation->setRelation('messages', $messages);
        $conversation->loadCount('messages');
        $conversation->load('lead');

        return new ConversationDetailResource($conversation);
    }

    /**
     * Give each conversation on the page its most recent message, in ONE extra query.
     *
     * Deliberately done here rather than as a `latestOfMany` relation on the model: Conversation
     * belongs to the Phase 3A conversation engine and this read-only feature has no business
     * editing it. Two queries per page is the same cost the relation would have had.
     *
     * @param  Collection<int, Conversation>  $conversations
     */
    private function attachLatestMessages(Collection $conversations): void
    {
        $ids = $conversations->pluck('id')->all();

        if ($ids === []) {
            return;
        }

        $latest = ConversationMessage::query()
            ->whereIn('conversation_id', $ids)
            ->whereIn('id', function ($query) use ($ids): void {
                $query->selectRaw('MAX(id)')
                    ->from('crm_messages')
                    ->whereIn('conversation_id', $ids)
                    ->groupBy('conversation_id');
            })
            ->get()
            ->keyBy('conversation_id');

        foreach ($conversations as $conversation) {
            $conversation->setRelation('latestMessage', $latest->get($conversation->id));
        }
    }
}
