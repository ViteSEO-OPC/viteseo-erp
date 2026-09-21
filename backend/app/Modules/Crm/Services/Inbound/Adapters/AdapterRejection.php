<?php

declare(strict_types=1);

namespace App\Modules\Crm\Services\Inbound\Adapters;

/**
 * One envelope item that did not become a CRM message, described safely.
 *
 * WHAT IT CARRIES IS THE WHOLE POINT. A rejection is written to logs and handed to whatever
 * decides whether a provider delivery gets acknowledged, so it may hold only what is safe to put
 * in both places: a closed-set code, the item's position in the envelope, and the provider's own
 * event id when there is one. No body, no payload, no sender identity — those are exactly the
 * fields a rejection is most tempted to quote and least allowed to.
 *
 * THE EVENT ID IS SANITIZED, NOT TRUSTED. It arrives from a provider payload like everything else,
 * so it is bounded and stripped of control characters before it is kept: a value that reaches logs
 * must not be able to carry a newline and forge a second log line, and an id the size of a message
 * body is not an id.
 */
final class AdapterRejection
{
    public readonly ?string $externalEventId;

    public readonly bool $recoverable;

    /**
     * @param  int|null  $itemIndex  Position in the envelope, so an operator can find the item in the provider's own console.
     */
    public function __construct(
        public readonly RejectionCode $code,
        public readonly ?int $itemIndex = null,
        ?string $externalEventId = null,
        ?bool $recoverable = null,
    ) {
        $this->externalEventId = SafeIdentifier::for($externalEventId, SafeIdentifier::EVENT_ID);
        // The code decides recoverability; the override exists for the rare case where a caller
        // knows better (a persistence failure it has already established is permanent).
        $this->recoverable = $recoverable ?? $code->isRecoverable();
    }

    public function isIgnored(): bool
    {
        return $this->code->isIgnored();
    }

    /**
     * The shape that goes into a log line.
     *
     * @return array<string, scalar|null>
     */
    public function toDiagnostics(): array
    {
        return [
            'code' => $this->code->value,
            'item_index' => $this->itemIndex,
            'external_event_id' => $this->externalEventId,
            'recoverable' => $this->recoverable,
        ];
    }
}
