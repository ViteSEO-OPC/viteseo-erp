<?php

namespace App\Modules\Crm\Services\Copilot;

use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Cost and failure limits on top of the route's 20-per-minute throttle.
 *
 *   - DAILY CAP per user (`copilot.ai.daily_limit`, default 100), counted per Asia/Manila day.
 *   - CIRCUIT BREAKER: `breaker_failures` consecutive PROVIDER failures (errors, timeouts, refusals,
 *     unreadable bodies — not answers the validator rejects) open it for `breaker_minutes`, for
 *     everyone, so an outage or an empty account is not hammered.
 *
 * FAILS CLOSED. A missing or non-positive limit, or a cache that cannot be read or written, means no
 * AI call — the Copilot keeps its deterministic reply. Nothing here touches NERBY or its spend state.
 */
class CopilotUsageGuard
{
    private const BREAKER_OPEN = 'copilot:ai:breaker:open';

    private const BREAKER_FAILURES = 'copilot:ai:breaker:failures';

    public function breakerOpen(): bool
    {
        try {
            return Cache::has(self::BREAKER_OPEN);
        } catch (Throwable) {
            return true;
        }
    }

    /** Count one AI attempt for this user today. False — and no AI call — when over the cap or unsure. */
    public function consumeDaily(User $user): bool
    {
        $limit = config('copilot.ai.daily_limit');
        if (! is_numeric($limit) || (int) $limit < 1) {
            return false;
        }

        $key = 'copilot:ai:daily:'.$user->id.':'.now('Asia/Manila')->toDateString();
        try {
            Cache::add($key, 0, now()->addDay());
            $used = Cache::increment($key);
        } catch (Throwable) {
            return false;
        }

        return is_int($used) && $used <= (int) $limit;
    }

    public function recordProviderFailure(): void
    {
        try {
            Cache::add(self::BREAKER_FAILURES, 0, now()->addMinutes(30));
            $failures = Cache::increment(self::BREAKER_FAILURES);
            if (is_int($failures) && $failures >= (int) config('copilot.ai.breaker_failures', 5)) {
                Cache::put(self::BREAKER_OPEN, true, now()->addMinutes((int) config('copilot.ai.breaker_minutes', 5)));
                Cache::forget(self::BREAKER_FAILURES);
            }
        } catch (Throwable) {
            // Best effort: if the cache is down, breakerOpen() already fails closed.
        }
    }

    public function recordProviderSuccess(): void
    {
        try {
            Cache::forget(self::BREAKER_FAILURES);
        } catch (Throwable) {
            // Best effort.
        }
    }
}
