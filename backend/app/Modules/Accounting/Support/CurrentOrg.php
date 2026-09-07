<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Support;

use App\Support\CurrentOrg as PlatformCurrentOrg;

/**
 * Backward-compatible shim. The tenant resolver now lives platform-wide at
 * {@see \App\Support\CurrentOrg}; this delegates so existing accounting code
 * (controllers, requests) keeps working while multi-tenancy rolls out.
 *
 * Prefer importing \App\Support\CurrentOrg directly in new code.
 */
class CurrentOrg
{
    public static function id(): ?int
    {
        return PlatformCurrentOrg::id();
    }
}
