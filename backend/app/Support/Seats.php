<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Subscription;
use Illuminate\Support\Facades\DB;

/**
 * Per-org user seats. A "seat" = one user account in the org (a member of the
 * org, regardless of which system's role they hold — seats are org-level, not
 * per-product). Every plan includes a base of 10; more are bought in blocks of
 * 10, each block adding a flat monthly fee. BLOCK_PRICE is a working default —
 * final number is the CEO's call.
 */
class Seats
{
    /** Seats included in every plan, no extra charge. */
    public const BASE = 10;

    /** Seats are added/removed a block at a time. */
    public const BLOCK = 10;

    /** Monthly price per extra block of 10 seats (PHP). Working default. */
    public const BLOCK_PRICE = 499.0;

    /** How many seats the org is currently using (members of the org). */
    public static function used(int $orgId): int
    {
        return DB::table('org_user')->where('org_id', $orgId)->count();
    }

    /** The org's seat cap (its subscription's limit, or the base if none). */
    public static function limitFor(int $orgId): int
    {
        return (int) (Subscription::where('org_id', $orgId)->value('seat_limit') ?? self::BASE);
    }

    /** Is there room to add one more member right now? */
    public static function canAdd(int $orgId): bool
    {
        return self::used($orgId) < self::limitFor($orgId);
    }

    /** Number of paid extra blocks implied by a seat limit (limit above BASE). */
    public static function extraBlocks(int $seatLimit): int
    {
        return (int) max(0, ($seatLimit - self::BASE) / self::BLOCK);
    }
}
