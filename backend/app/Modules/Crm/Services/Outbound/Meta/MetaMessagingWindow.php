<?php

declare(strict_types=1);

namespace App\Modules\Crm\Services\Outbound\Meta;

use App\Modules\Crm\Models\Conversation;
use App\Modules\Crm\Services\Outbound\OutboundFailureCode;
use DateTimeImmutable;

/**
 * The conservative local answer to "may we reply in this thread at all?".
 *
 * WHAT THIS IMPLEMENTS, AND ONLY THIS. Meta allows a business to reply to a customer within a
 * standard window that opens at the customer's most recent message. Outside it, a normal reply is
 * refused. There are documented ways to send outside that window — message tags, the human agent
 * allowance, paid and utility messaging — and NONE of them are implemented here. Each one carries
 * its own eligibility rules and its own review, and a bypass added speculatively is a bypass nobody
 * has checked. So this class knows one rule, and refuses everything it is unsure about.
 *
 * WHY IT RUNS IN `validate()` RATHER THAN BESIDE THE TRANSPORT. The adapter contract says
 * `validate()` is for cheap checks made before any credential work, "because a request that can
 * never be sent should not cost a lookup". A window check IS a lookup, so this stretches that rule
 * — deliberately, for one reason: the service persists a `pending` row just before it calls the
 * transport, and a refusal raised any later would leave a `failed` outbound row sitting in a thread
 * that an agent and eventually a customer-facing inbox can see. A message that was never attempted
 * should leave no trace in the conversation. The lookup is one indexed read on
 * (conversation_id, occurred_at), which is the index `crm_messages` already carries.
 *
 * THE BOUNDARY IS CLOSED, NOT OPEN. At exactly `occurred_at + N hours` this refuses. Our clock and
 * Meta's are not the same clock, and the failure modes are not symmetric: refusing a send that
 * would have been allowed costs an operator one confused moment, while allowing one Meta rejects
 * costs a `failed` row and a support conversation about a message that never arrived.
 *
 * THE ORG PREDICATE IS EXPLICIT AND THE GLOBAL SCOPE IS DROPPED, for the same reason
 * {@see \App\Modules\Crm\Services\Outbound\OutboundResourceResolver} does it: `CurrentOrg::id()` is
 * null outside an HTTP request and outbound is meant to be queueable, so leaving the scope on would
 * make this find nothing in a job — failing closed, but for a reason that looks like missing data.
 */
final class MetaMessagingWindow
{
    /**
     * Why this thread may not be replied to, or null when it may.
     *
     * Returns a sentence rather than a boolean because the two refusals are different problems:
     * a thread with no inbound turn at all has never been opened by a customer, while an expired
     * one was open and closed. Both map to {@see OutboundFailureCode::OutsideMessagingWindow}; only
     * the wording tells the operator which happened.
     */
    public function refusalFor(Conversation $conversation, ?DateTimeImmutable $now = null): ?string
    {
        $latest = $this->latestInboundAt($conversation);

        if ($latest === null) {
            return 'No inbound customer message has been recorded on this conversation, so no reply window is open.';
        }

        $hours = $this->hours();
        $closesAt = $latest->modify('+'.$hours.' hours');
        // `now()` rather than `new DateTimeImmutable`: it honours Carbon's test clock, which is
        // what lets the boundary be asserted to the second instead of approximated.
        $now ??= now()->toDateTimeImmutable();

        // `>=`, not `>`: the instant the window closes, it is closed.
        if ($now >= $closesAt) {
            return sprintf(
                'The %d-hour reply window closed; the most recent inbound customer message is older than that.',
                $hours,
            );
        }

        return null;
    }

    /** The configured window, in hours. Floored at 1 so a misconfigured 0 cannot open it forever. */
    public function hours(): int
    {
        return max(1, (int) config('meta.outbound.window_hours', 24));
    }

    /**
     * When the customer last spoke in this thread.
     *
     * `occurred_at` rather than `received_at` or `created_at`: the window runs from when the
     * customer sent the message, which is what Meta stamps and what our ingest records. A delivery
     * we processed late does not get a later deadline than the customer's own clock gives it.
     */
    private function latestInboundAt(Conversation $conversation): ?DateTimeImmutable
    {
        $value = $conversation->messages()
            ->withoutGlobalScopes()
            ->reorder()
            ->where('org_id', (int) $conversation->org_id)
            ->where('direction', 'inbound')
            ->whereNotNull('occurred_at')
            ->orderByDesc('occurred_at')
            ->value('occurred_at');

        if ($value === null) {
            return null;
        }

        // Eloquent hands back a Carbon for a cast column and a raw string otherwise; both are
        // accepted rather than assuming which, because this query bypasses the model's casts.
        return $value instanceof \DateTimeInterface
            ? DateTimeImmutable::createFromInterface($value)
            : new DateTimeImmutable((string) $value);
    }
}
