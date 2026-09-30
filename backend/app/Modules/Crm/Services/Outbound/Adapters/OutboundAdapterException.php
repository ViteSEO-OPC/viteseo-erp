<?php

declare(strict_types=1);

namespace App\Modules\Crm\Services\Outbound\Adapters;

use RuntimeException;

/** Base for the two ways adapter selection can fail. Carries identifiers only, never a message body. */
abstract class OutboundAdapterException extends RuntimeException {}
