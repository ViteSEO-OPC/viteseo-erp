<?php

declare(strict_types=1);

namespace App\Modules\Crm\Services\Outbound;

use RuntimeException;

/**
 * The outbound row could neither be written nor recovered.
 *
 * Never chains the driver exception: a QueryException embeds its bindings in its message, and a
 * binding on this path is the customer's message text.
 */
final class OutboundPersistenceException extends RuntimeException {}
