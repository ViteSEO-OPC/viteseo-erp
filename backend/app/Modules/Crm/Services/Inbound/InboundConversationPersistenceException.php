<?php

declare(strict_types=1);

namespace App\Modules\Crm\Services\Inbound;

use Illuminate\Database\QueryException;
use RuntimeException;

/**
 * A database failure during inbound conversation persistence, restated WITHOUT the query.
 *
 * WHY THIS EXISTS. `QueryException::formatMessage()` builds its message as
 * `…, SQL: <query with the bindings substituted in>`. For this pipeline the bindings are the
 * message body and the provider's raw payload, so letting a QueryException reach the logger would
 * put exactly the two things this subsystem is careful never to log into the application log — via
 * the framework's own exception renderer, with no code of ours involved.
 *
 * The original exception is deliberately NOT chained as `previous`. A chained cause is printed by
 * the renderer and by most log formatters, which would reintroduce the leak through the back door.
 * What survives instead is what a support engineer can actually act on: the SQLSTATE class and the
 * safe identifiers that locate the event at the provider and in our own tables.
 *
 * The trade-off is accepted knowingly: the SQL and the driver's own text are lost. If a future
 * phase needs them, the right answer is a redaction-aware log channel, not a chained exception.
 */
final class InboundConversationPersistenceException extends RuntimeException
{
    /**
     * @param  array<string, scalar|null>  $diagnostics  identifiers only — never body or payload
     */
    public static function from(QueryException $exception, array $diagnostics): self
    {
        $sqlState = (string) $exception->getCode();

        $context = implode(' ', array_map(
            static fn (string $key, mixed $value): string => $key.'='.($value === null ? '-' : (string) $value),
            array_keys($diagnostics),
            array_values($diagnostics),
        ));

        return new self(sprintf(
            'Inbound conversation persistence failed [SQLSTATE %s] %s',
            $sqlState === '' ? 'unknown' : $sqlState,
            $context,
        ));
    }
}
