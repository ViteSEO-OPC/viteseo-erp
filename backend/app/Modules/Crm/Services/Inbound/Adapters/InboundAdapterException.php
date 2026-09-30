<?php

declare(strict_types=1);

namespace App\Modules\Crm\Services\Inbound\Adapters;

use App\Modules\Crm\Services\Inbound\InboundConversationPersistenceException;
use App\Modules\Crm\Services\Inbound\SafeIdentifier;
use RuntimeException;

/**
 * An adapter-layer failure, stated without the payload that caused it.
 *
 * SAME RULE AS {@see InboundConversationPersistenceException},
 * for the same reason. That class exists because a QueryException embeds its bindings — message
 * body and raw payload — in its own message, so a log line that records the exception records the
 * customer's conversation. The adapter layer has the identical hazard from the other direction:
 * the obvious way to explain a normalization failure is to quote the thing that failed to parse.
 *
 * So nothing here is ever constructed from provider data, and a provider-thrown exception is
 * NEVER chained: a chained cause is printed by the framework's renderer, which would carry the
 * payload straight back into the log this class exists to keep clean. The original exception's
 * CLASS NAME is kept — it names the fault without quoting the data.
 *
 * Provider and channel do appear, because they are chosen by our own routing, not by the payload;
 * they are bounded anyway, on the principle that anything printed should be shaped by us.
 */
class InboundAdapterException extends RuntimeException
{
    private const MAX_IDENTIFIER = 64;

    /**
     * The adapter threw. What we keep is what a support engineer can act on: which adapter, which
     * provider and channel, and the class of the original fault.
     */
    public static function normalizationFailed(
        string $adapter,
        string $provider,
        string $channel,
        string $originalClass,
    ): self {
        return new self(sprintf(
            'Adapter [%s] failed to normalize a %s/%s envelope (%s). The provider payload is deliberately omitted.',
            self::identifier($adapter),
            self::identifier($provider),
            self::identifier($channel),
            self::identifier($originalClass, 191),
        ));
    }

    /** Delegated so the sanitization rule lives in exactly one place. */
    protected static function identifier(string $value, int $max = self::MAX_IDENTIFIER): string
    {
        return SafeIdentifier::orUnknown($value, $max);
    }
}
