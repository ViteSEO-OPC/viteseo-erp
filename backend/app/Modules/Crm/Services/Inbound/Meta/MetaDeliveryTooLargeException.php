<?php

declare(strict_types=1);

namespace App\Modules\Crm\Services\Inbound\Meta;

use RuntimeException;

/**
 * The delivery carried more events than the configured ceiling.
 *
 * Its own type so the controller can answer 413 without inspecting a message string, and thrown
 * BEFORE any group is processed so the refusal is all-or-nothing rather than a half-written
 * delivery spread across two tenants.
 *
 * The counts are safe to carry: they are ours, not the provider's content.
 */
final class MetaDeliveryTooLargeException extends RuntimeException
{
    public function __construct(public readonly int $events, public readonly int $ceiling)
    {
        parent::__construct(sprintf('Meta delivery carried %d events; the ceiling is %d.', $events, $ceiling));
    }
}
