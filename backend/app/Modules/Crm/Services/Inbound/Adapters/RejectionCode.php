<?php

declare(strict_types=1);

namespace App\Modules\Crm\Services\Inbound\Adapters;

/**
 * Why one item of a provider envelope did not become a CRM message.
 *
 * A CLOSED SET, deliberately. The alternative — free-text reasons — is how provider payloads end
 * up in logs: "could not parse {json}" is a leak written by someone who only meant to be helpful.
 * A case here carries no data at all, so there is nothing to leak, and operators get a value they
 * can count and alert on instead of a sentence they have to grep.
 *
 * RECOVERABILITY IS A PROPERTY OF THE CODE, not of the caller's mood. A malformed event will still
 * be malformed on the provider's next delivery, so retrying it is pure waste; an infrastructure
 * failure is exactly the case where retrying is the whole point. Phase 3D's webhook will read this
 * to decide between acknowledging a delivery and asking for it again.
 */
enum RejectionCode: string
{
    /** A real event, but not a conversation turn: a delivery receipt, a read receipt, an echo. */
    case IgnoredNonMessage = 'ignored_non_message';

    /** The item's shape is not what the provider documents. */
    case MalformedEvent = 'malformed_event';

    /** A conversation turn we have no representation for (a call invite, a payment object). */
    case UnsupportedMessageType = 'unsupported_message_type';

    /** No usable thread/sender identity, so the turn could not be placed in a conversation. */
    case MissingProviderIdentity = 'missing_provider_identity';

    /** The item belongs to a channel this adapter does not own. */
    case UnsupportedChannel = 'unsupported_channel';

    /** The adapter itself failed — a bug or an infrastructure fault, not bad data. */
    case NormalizationFailed = 'normalization_failed';

    /**
     * The turn normalized cleanly and the database refused the write.
     *
     * Beyond the codes named in the Phase 3C brief, and deliberately so: reusing
     * `normalization_failed` for a database fault would put two unrelated causes behind one
     * counter, and the two differ in the only way that matters operationally — this one usually
     * clears by itself, so the sibling messages are worth re-delivering and the adapter is not
     * the thing to investigate.
     */
    case PersistenceFailed = 'persistence_failed';

    /** Whether a retry of the same delivery could plausibly succeed. */
    public function isRecoverable(): bool
    {
        return match ($this) {
            self::NormalizationFailed, self::PersistenceFailed => true,
            default => false,
        };
    }

    /** An ignored event is a NORMAL outcome; it is counted apart from genuine rejections. */
    public function isIgnored(): bool
    {
        return $this === self::IgnoredNonMessage;
    }
}
