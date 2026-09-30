<?php

declare(strict_types=1);

namespace App\Modules\Crm\Services\Inbound\Meta\Adapters;

use App\Modules\Crm\Services\Inbound\Adapters\AdapterNormalizationResult;
use App\Modules\Crm\Services\Inbound\Adapters\AdapterRejection;
use App\Modules\Crm\Services\Inbound\Adapters\RejectionCode;
use App\Modules\Crm\Services\Inbound\Contracts\InboundProviderAdapter;
use App\Modules\Crm\Services\Inbound\InboundConversationMessage;
use Carbon\CarbonImmutable;

/**
 * Everything Messenger and Instagram DM share, which is nearly all of it.
 *
 * Both channels deliver the same `messaging[]` grammar — same sender/recipient pair, same `mid`,
 * same millisecond timestamp, same attachment envelope. Only the channel label and the meaning of
 * the identities differ (PSID + Page vs IGSID + IG business account). Writing the extraction twice
 * is how one copy gets a fix and the other keeps shipping the old shape for months, so it lives
 * here and the subclasses carry only what genuinely differs.
 *
 * WHAT THIS ADAPTER WILL NOT DO, because the limits that protect the trusted Phase 3B boundary live
 * in a FormRequest that this path never touches: every per-item cap is re-applied here, from the
 * same `config('inbound.limits')` keys. Without that, the public webhook would be the one door into
 * the conversation engine with no size, depth or text ceiling.
 *
 * REJECTING RATHER THAN TRUNCATING is a deliberate choice for an over-limit item. A silently
 * shortened customer message is a lie told to whoever reads the thread later, and it is the kind of
 * lie nobody can detect afterwards; a rejection is visible, counted and diagnosable.
 *
 * ONE META MESSAGE IS ONE CRM MESSAGE. A message carrying text and three attachments produces a
 * single turn with a single `mid`, never four rows sharing an id — the id is what makes replay safe,
 * and duplicating it would break that. See `messageTypeFor()` for what the single `message_type`
 * can and cannot express.
 *
 * Pure: no clock, no HTTP, no persistence, no state between calls.
 */
abstract class AbstractMetaMessagingAdapter implements InboundProviderAdapter
{
    /** Attachment kinds the Phase 3A contract can name faithfully. Anything else is refused, not relabelled. */
    private const ATTACHMENT_TYPES = [
        'image' => 'image',
        'video' => 'video',
        'audio' => 'audio',
        'file' => 'file',
        'template' => 'template',
    ];

    abstract public function name(): string;

    /** messenger | instagram_dm */
    abstract protected function channel(): string;

    /** Meta's `object` value this adapter belongs to — used only to build the thread prefix. */
    abstract protected function threadPrefix(): string;

    public function supports(string $provider, string $channel): bool
    {
        return $provider === 'meta' && $channel === $this->channel();
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return iterable<int, InboundConversationMessage>
     */
    public function normalize(array $payload): iterable
    {
        return $this->normalizeEnvelope($payload)->messages;
    }

    /** @param  array<string, mixed>  $payload */
    public function normalizeEnvelope(array $payload): AdapterNormalizationResult
    {
        $items = $payload['items'] ?? null;

        if (! is_array($items)) {
            return AdapterNormalizationResult::of([], [new AdapterRejection(RejectionCode::MalformedEvent)]);
        }

        // The resource this group was resolved under, when the caller knows it. Optional so the
        // adapter stays usable on its own (and so a direct unit test need not construct a group),
        // but supplied by every real delivery.
        $resource = $this->stringOrNull($payload['resource_id'] ?? null);

        $messages = [];
        $rejections = [];

        foreach (array_values($items) as $index => $item) {
            $outcome = $this->normalizeItem($index, $item, $resource);

            if ($outcome instanceof AdapterRejection) {
                $rejections[] = $outcome;

                continue;
            }

            $messages[] = $outcome;
        }

        return AdapterNormalizationResult::of($messages, $rejections);
    }

    private function normalizeItem(int $index, mixed $item, ?string $resource = null): InboundConversationMessage|AdapterRejection
    {
        if (! is_array($item)) {
            return new AdapterRejection(RejectionCode::MalformedEvent, $index);
        }

        $ignored = $this->ignoredReason($item);

        if ($ignored !== null) {
            return new AdapterRejection(RejectionCode::IgnoredNonMessage, $index, $ignored);
        }

        $sender = $this->identity($item['sender'] ?? null);
        $recipient = $this->identity($item['recipient'] ?? null);

        if ($sender === null || $recipient === null) {
            // Without both halves there is no thread to place the turn in, and the recipient is
            // also the Meta resource — inventing either would be inventing an identity.
            return new AdapterRejection(RejectionCode::MissingProviderIdentity, $index);
        }

        if ($resource !== null && $recipient !== $resource) {
            /*
             * THE ITEM IS NOT ADDRESSED TO THE RESOURCE THAT DECIDED THE TENANT.
             *
             * The org was resolved from the delivery's `entry[].id`; the thread is keyed by the
             * item's own `recipient.id`. In real Meta traffic those are the same Page, and nothing
             * in the format promises it — so when they disagree, storing the turn would place it in
             * one Page's tenant under another Page's thread identity. Refusing is the only answer
             * that keeps the two identities from drifting apart, and it costs only the item.
             */
            return new AdapterRejection(RejectionCode::MissingProviderIdentity, $index, $this->mid(is_array($item['message'] ?? null) ? $item['message'] : null));
        }

        $message = is_array($item['message'] ?? null) ? $item['message'] : null;
        $postback = is_array($item['postback'] ?? null) ? $item['postback'] : null;

        if ($message === null && $postback === null) {
            return new AdapterRejection(RejectionCode::IgnoredNonMessage, $index);
        }

        $type = $postback !== null ? 'postback' : $this->messageTypeFor($message ?? []);

        if ($type === null) {
            return new AdapterRejection(RejectionCode::UnsupportedMessageType, $index, $this->mid($message));
        }

        $text = $postback !== null
            ? $this->stringOrNull($postback['title'] ?? null)
            : $this->stringOrNull($message['text'] ?? null);

        $limit = (int) config('inbound.limits.text', 20000);

        if ($text !== null && mb_strlen($text) > $limit) {
            // Not truncated: a shortened customer message would read as the whole of what they said.
            return new AdapterRejection(RejectionCode::MalformedEvent, $index, $this->mid($message));
        }

        if (! $this->payloadWithinLimits($item)) {
            return new AdapterRejection(RejectionCode::MalformedEvent, $index, $this->mid($message));
        }

        return new InboundConversationMessage(
            provider: 'meta',
            channel: $this->channel(),
            // Thread identity is (resource, sender). The recipient of an inbound message IS the
            // Page or IG account, so the same person writing to two of an org's Pages is two
            // threads — which is what a human reading either thread would expect. No org id
            // appears here: the identifier must mean the same thing before tenancy is resolved.
            externalConversationId: $this->threadPrefix().':'.$recipient.':from:'.$sender,
            externalMessageId: $this->mid($message),
            // Meta issues no per-delivery event id worth recording; inventing one would weaken the
            // derived idempotency key rather than strengthen it.
            externalEventId: null,
            senderExternalId: $sender,
            recipientExternalId: $recipient,
            messageType: $type,
            text: $text,
            occurredAt: $this->occurredAt($item['timestamp'] ?? null),
            // The COMPLETE messaging item, so nothing is discarded even when the single
            // message_type cannot express every attachment. Per-item slice, never the envelope:
            // Phase 3A hashes this for turns without a mid.
            rawPayload: $item,
        );
    }

    /**
     * Why an item is knowingly not a conversation turn.
     *
     * Returned as a safe label for the rejection's event-id slot, so a log shows WHICH kind of
     * traffic was skipped without carrying any of its content.
     */
    private function ignoredReason(array $item): ?string
    {
        foreach (['delivery', 'read', 'reaction', 'standby', 'optin', 'referral', 'account_linking'] as $key) {
            if (isset($item[$key])) {
                return $key;
            }
        }

        $message = $item['message'] ?? null;

        if (is_array($message) && ($message['is_echo'] ?? false) === true) {
            // Our own outbound reply coming back. Ingesting it would record what we said as
            // something the customer said.
            return 'echo';
        }

        return null;
    }

    /**
     * The single `message_type` for one Meta message.
     *
     * THE LIMITATION, STATED PLAINLY: the Phase 3A contract carries one message type and one text
     * per turn, while a Meta message may hold text AND several attachments of mixed kinds. This
     * resolves deterministically — a story reply is `story_reply`, otherwise the first attachment's
     * kind wins, otherwise `text` — and the complete item is preserved in `rawPayload`, so nothing
     * is lost even though the type names only the leading part. Widening the contract to express
     * "text plus two images" is a Phase 3A change and is deliberately not made here.
     */
    private function messageTypeFor(array $message): ?string
    {
        if (is_array($message['reply_to'] ?? null) && isset($message['reply_to']['story'])) {
            return 'story_reply';
        }

        $attachments = $message['attachments'] ?? null;

        if (is_array($attachments) && $attachments !== []) {
            $first = array_values($attachments)[0];
            $kind = is_array($first) ? $this->stringOrNull($first['type'] ?? null) : null;

            return $kind !== null ? (self::ATTACHMENT_TYPES[$kind] ?? null) : null;
        }

        if ($this->stringOrNull($message['text'] ?? null) !== null) {
            return 'text';
        }

        return null;
    }

    /** The same encoded-size and depth ceilings the trusted Phase 3B boundary applies. */
    private function payloadWithinLimits(array $item): bool
    {
        $encoded = json_encode($item, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);

        if ($encoded === false) {
            return false;
        }

        if (strlen($encoded) > (int) config('inbound.limits.raw_payload_bytes', 65536)) {
            return false;
        }

        return $this->depthOf($item) <= (int) config('inbound.limits.raw_payload_depth', 8);
    }

    private function depthOf(mixed $value, int $current = 1): int
    {
        if (! is_array($value)) {
            return $current;
        }

        $deepest = $current;

        foreach ($value as $child) {
            if (is_array($child)) {
                $deepest = max($deepest, $this->depthOf($child, $current + 1));
            }
        }

        return $deepest;
    }

    private function mid(?array $message): ?string
    {
        return $message === null ? null : $this->stringOrNull($message['mid'] ?? null);
    }

    /** Meta sends `sender`/`recipient` as objects carrying an id. */
    private function identity(mixed $party): ?string
    {
        if (! is_array($party)) {
            return null;
        }

        $id = $party['id'] ?? null;

        if (is_int($id)) {
            return (string) $id;
        }

        return $this->stringOrNull(is_string($id) ? $id : null);
    }

    /**
     * Meta timestamps are milliseconds. Normalized to ISO-8601 UTC with no reference to the current
     * time — a value that changed between two runs would change the derived idempotency key with it.
     */
    private function occurredAt(mixed $timestamp): ?string
    {
        if (is_int($timestamp) || (is_string($timestamp) && ctype_digit($timestamp))) {
            return CarbonImmutable::createFromTimestampMs((int) $timestamp, 'UTC')->toIso8601String();
        }

        return null;
    }

    private function stringOrNull(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}
