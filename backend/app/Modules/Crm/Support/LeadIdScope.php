<?php

namespace App\Modules\Crm\Support;

use Illuminate\Http\Request;

/**
 * The optional `lead_ids` scope on the Website Intelligence queues: `?lead_ids=12,15,40`.
 *
 * NULL means the parameter was absent — the whole queue, exactly as before it existed. Present, it is
 * the list of positive integer ids it names, capped at the queues' own 200-row ceiling; a present but
 * empty or wholly malformed value is an EMPTY scope (nothing), never a silent fallback to everything.
 * Tenancy is untouched: the ids only narrow a query that is already confined to the caller's org.
 */
final class LeadIdScope
{
    public const MAX_IDS = 200;

    /** @return list<int>|null */
    public static function from(Request $request): ?array
    {
        if (! $request->has('lead_ids')) {
            return null;
        }

        $ids = [];
        foreach (explode(',', (string) $request->query('lead_ids', '')) as $part) {
            $part = trim($part);
            if ($part !== '' && ctype_digit($part) && (int) $part > 0) {
                $ids[(int) $part] = true;
            }
            if (count($ids) >= self::MAX_IDS) {
                break;
            }
        }

        return array_keys($ids);
    }
}
