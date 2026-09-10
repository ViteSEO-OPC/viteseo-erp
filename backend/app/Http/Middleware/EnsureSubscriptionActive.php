<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\Subscription;
use App\Services\SubscriptionService;
use App\Support\CurrentOrg;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Prepaid gate: blocks product access unless the current org's subscription is
 * paid and within its period. Apply AFTER set-current-org. Super-admins bypass
 * (platform owner / support). A blocked request returns 402 Payment Required so
 * the frontend can show a "renew to continue" paywall.
 */
class EnsureSubscriptionActive
{
    public function __construct(
        private readonly SubscriptionService $subscriptions,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if ($user && $user->hasRole('super_admin')) {
            return $next($request);
        }

        $orgId = CurrentOrg::id();
        $subscription = $orgId !== null
            ? Subscription::where('org_id', $orgId)->first()
            : null;

        if (! $subscription || ! $this->subscriptions->hasAccess($subscription)) {
            return response()->json([
                'message' => 'Your subscription is not active. Please renew to continue.',
                'subscription_status' => $subscription?->status ?? 'none',
            ], 402);
        }

        return $next($request);
    }
}
