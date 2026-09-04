<?php

declare(strict_types=1);

namespace App\Modules\Hr\Support;

use App\Models\Org;
use App\Models\Subscription;
use App\Services\SubscriptionService;
use App\Support\Entitlements;

/**
 * Which orgs the HR console jobs should run for.
 *
 * The scheduled commands (management attendance, leave accrual) used to run
 * once against "the" company; with multi-tenancy they iterate every org that
 * has availed `hr` AND is paid up — the same entitlement + prepaid gate the
 * API applies per request (`subscription-active` + `feature:hr`) and the same
 * shape Booking's reminder command uses. A lapsed org's balances simply stop
 * moving until it renews, exactly like its API access.
 */
class HrOrgs
{
    /** @return list<int> Org ids entitled to `hr` with an active/trialing subscription. */
    public static function eligibleOrgIds(): array
    {
        $subscriptions = app(SubscriptionService::class);

        return Org::query()
            ->orderBy('id')
            ->pluck('id')
            ->filter(function ($orgId) use ($subscriptions): bool {
                $orgId = (int) $orgId;

                if (! Entitlements::has($orgId, 'hr')) {
                    return false;
                }

                $subscription = Subscription::where('org_id', $orgId)->first();

                return $subscription !== null && $subscriptions->hasAccess($subscription);
            })
            ->map(fn ($orgId): int => (int) $orgId)
            ->values()
            ->all();
    }
}
