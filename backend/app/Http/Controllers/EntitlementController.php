<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Org;
use App\Models\Subscription;
use App\Services\SubscriptionService;
use App\Support\CurrentOrg;
use App\Support\Entitlements;
use App\Support\FeatureRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Reads and (for super-admins) sets which functions an org has availed.
 * The frontend launcher uses `mine()` to show only availed modules; the admin
 * endpoints back the "pick functions" panel.
 */
class EntitlementController extends Controller
{
    public function __construct(
        private readonly SubscriptionService $subscriptions,
    ) {}

    /** GET /api/v1/me/features — the current org's availed functions + billing state. */
    public function mine(): JsonResponse
    {
        $orgId = CurrentOrg::id();
        $keys = $orgId !== null ? Entitlements::keysFor($orgId) : [];

        $subscription = $orgId !== null ? Subscription::where('org_id', $orgId)->first() : null;
        if ($subscription) {
            $this->subscriptions->refreshStatus($subscription);
        }

        return response()->json([
            'data' => [
                'org_id' => $orgId,
                'features' => $keys,
                'modules' => $this->enabledModules($keys),
                'subscription' => $subscription ? [
                    'plan' => $subscription->plan,
                    'status' => $subscription->status,
                    'is_active' => $subscription->isCurrentlyActive(),
                    'current_period_end' => $subscription->current_period_end?->toIso8601String(),
                    'monthly_price' => (float) $subscription->monthly_price,
                ] : null,
            ],
        ]);
    }

    /** GET /api/v1/admin/features — the full feature catalog (for onboarding). */
    public function catalog(): JsonResponse
    {
        $catalog = [];
        foreach (FeatureRegistry::all() as $key => $meta) {
            $catalog[] = [
                'key' => $key,
                'label' => $meta['label'],
                'module' => $meta['module'],
                'requires' => $meta['requires'],
            ];
        }

        return response()->json(['data' => $catalog]);
    }

    /** GET /api/v1/admin/orgs/{org}/features — full catalog + enabled flag. */
    public function show(Org $org): JsonResponse
    {
        $enabled = Entitlements::keysFor($org->id);

        $catalog = [];
        foreach (FeatureRegistry::all() as $key => $meta) {
            $catalog[] = [
                'key' => $key,
                'label' => $meta['label'],
                'module' => $meta['module'],
                'requires' => $meta['requires'],
                'enabled' => in_array($key, $enabled, true),
            ];
        }

        return response()->json([
            'data' => ['org_id' => $org->id, 'features' => $catalog],
        ]);
    }

    /** PUT /api/v1/admin/orgs/{org}/features — set the org's availed functions. */
    public function update(Request $request, Org $org): JsonResponse
    {
        $data = $request->validate([
            'features' => ['present', 'array'],
            'features.*' => ['string', Rule::in(FeatureRegistry::keys())],
        ]);

        $resolved = Entitlements::sync($org->id, $data['features']);

        return response()->json([
            'data' => ['org_id' => $org->id, 'features' => $resolved],
        ]);
    }

    /** @param  list<string>  $keys @return list<string> */
    private function enabledModules(array $keys): array
    {
        return array_values(array_filter(
            FeatureRegistry::modules(),
            static fn (string $module): bool => in_array($module, $keys, true),
        ));
    }
}
