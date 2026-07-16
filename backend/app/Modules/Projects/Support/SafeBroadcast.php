<?php

declare(strict_types=1);

namespace App\Modules\Projects\Support;

use Illuminate\Support\Facades\Log;

/**
 * Fire-and-forget event broadcasting.
 *
 * Our events broadcast synchronously (ShouldBroadcastNow) so delivery never
 * depends on a running queue worker. The trade-off is that a transient Reverb
 * outage would otherwise throw a BroadcastException straight into — and 500 —
 * the triggering request. These helpers guarantee real-time degrades silently:
 * the DB change still succeeds and the API responds normally; only the live
 * push is skipped (and logged).
 */
final class SafeBroadcast
{
    /** Broadcast to everyone except the current connection (the actor). */
    public static function toOthers(object $event): void
    {
        self::send($event, true);
    }

    /** Broadcast to every subscriber, including the current connection. */
    public static function dispatch(object $event): void
    {
        self::send($event, false);
    }

    private static function send(object $event, bool $toOthers): void
    {
        try {
            $pending = broadcast($event);
            if ($toOthers) {
                $pending->toOthers();
            }
            // Force the (synchronous) send to happen *inside* this try — the
            // actual broadcast fires in PendingBroadcast::__destruct().
            unset($pending);
        } catch (\Throwable $e) {
            Log::warning('Realtime broadcast skipped: '.$e->getMessage());
        }
    }
}
