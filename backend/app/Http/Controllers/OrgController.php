<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Org;
use App\Models\Subscription;
use App\Models\SubscriptionPayment;
use App\Services\OrgOnboardingService;
use App\Services\SubscriptionService;
use App\Support\FeatureRegistry;
use App\Support\Seats;
use App\Support\SystemRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Super-admin tenant onboarding + prepaid billing control. Provisions a new
 * accounting org + first admin + a pending subscription; and records payments
 * (activate) / pauses access (suspend) for the prepaid-suspend model.
 */
class OrgController extends Controller
{
    public function __construct(
        private readonly OrgOnboardingService $onboarding,
        private readonly SubscriptionService $subscriptions,
    ) {}

    /**
     * GET /api/v1/admin/systems — the SaaS-ready systems a tenant can avail, each
     * with its own tiers + priced add-on functions. Drives the multi-product
     * onboarding picker (Accounting and/or Inventory, each on its own tier).
     */
    public function systems(): JsonResponse
    {
        return response()->json(['data' => SystemRegistry::catalog()]);
    }

    /**
     * GET /api/v1/admin/revenue-schedules — kept as an empty, honest answer.
     *
     * This used to read deferred-revenue schedules and the Unearned Revenue
     * balance out of the provider's own books. Those books were the Accounting
     * module, which is Kiplo's product and no longer lives here. Billing is manual
     * now (see SubscriptionBillingService), so there is nothing to defer and
     * nothing to recognise -- and an endpoint the admin UI still calls is better
     * returning an empty set than a 500.
     */
    public function revenueSchedules(): JsonResponse
    {
        return response()->json(['data' => ['unearned_balance' => 0, 'schedules' => []]]);
    }

    /** GET /api/v1/admin/orgs — every tenant + its subscription (billing view). */
    public function index(): JsonResponse
    {
        $subs = Subscription::query()->get()->keyBy('org_id');

        $orgs = Org::query()->orderBy('name')->get()->map(function (Org $org) use ($subs) {
            $s = $subs->get($org->id);
            if ($s) {
                $this->subscriptions->refreshStatus($s); // lazily reflect lapses
            }

            return [
                'id' => $org->id,
                'name' => $org->name,
                'subscription' => $s ? $this->subscriptionPayload($s) : null,
            ];
        });

        return response()->json(['data' => $orgs]);
    }

    /**
     * POST /api/v1/admin/orgs — provision a new multi-product tenant. Availed
     * systems come as `systems: { accounting: 'basic', inventory: 'pro' }`; each
     * value is a tier of that system. The legacy single-`plan` payload (accounting
     * only) is still accepted so nothing that predates the picker breaks.
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'admin_name' => ['required', 'string', 'max:255'],
            'admin_email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'admin_password' => ['required', 'string', 'min:8'],
            /*
             * The systems map is the only shape now. A lone `plan` key used to be
             * accepted as "accounting on that tier" -- a fallback from when there was
             * one product. Accounting left this repo, and with it the registry that
             * validated the key, so the fallback was not merely obsolete: it made
             * BOTH onboarding paths throw before validation even ran.
             */
            'systems' => ['required', 'array', 'min:1'],
            'features' => ['nullable', 'array'],
            'features.*' => ['string', Rule::in(FeatureRegistry::keys())],
        ]);

        // Resolve the availed systems.
        $systems = [];
        foreach ($data['systems'] ?? [] as $system => $tier) {
            if (! is_string($tier) || $tier === '') {
                continue; // an un-availed system arrives as null / "".
            }
            if (! SystemRegistry::exists($system) || ! SystemRegistry::tierExists($system, $tier)) {
                throw ValidationException::withMessages([
                    "systems.$system" => "Invalid plan for the {$system} system.",
                ]);
            }
            $systems[$system] = $tier;
        }
        if ($systems === []) {
            throw ValidationException::withMessages([
                'systems' => 'Avail at least one system.',
            ]);
        }

        $result = $this->onboarding->createOrg(
            $data['name'],
            $data['admin_name'],
            $data['admin_email'],
            $data['admin_password'],
            $systems,
            $data['features'] ?? [],
            $request->user(),
        );

        return response()->json([
            'data' => [
                'org' => ['id' => $result['org']->id, 'name' => $result['org']->name],
                'admin' => ['id' => $result['admin']->id, 'email' => $result['admin']->email],
                'features' => $result['features'],
                'subscription' => $this->subscriptionPayload($result['subscription']),
            ],
        ], 201);
    }

    /**
     * POST /api/v1/admin/orgs/{org}/activate — record a payment: (re)activate and
     * extend the paid period by {months} (default 1). This is how the prepaid
     * model opens/renews access.
     */
    public function activate(Request $request, Org $org): JsonResponse
    {
        $data = $request->validate([
            'months' => ['nullable', 'integer', 'min:1', 'max:36'],
        ]);

        $subscription = Subscription::where('org_id', $org->id)->firstOrFail();
        $this->subscriptions->activate($subscription, $data['months'] ?? 1, $request->user());

        return response()->json(['data' => $this->subscriptionPayload($subscription->fresh())]);
    }

    /**
     * POST /api/v1/admin/orgs/{org}/seats — set the org's user seat cap. Seats
     * come in blocks of 10 (base 10 free); each extra block adjusts the monthly
     * price by the block fee. Can't drop below what the org already uses.
     */
    public function setSeats(Request $request, Org $org): JsonResponse
    {
        $data = $request->validate([
            'seat_limit' => ['required', 'integer', 'min:'.Seats::BASE, 'max:500'],
        ]);

        $new = (int) $data['seat_limit'];
        if ($new % Seats::BLOCK !== 0) {
            throw ValidationException::withMessages([
                'seat_limit' => 'Seats are added in blocks of '.Seats::BLOCK.'.',
            ]);
        }

        $used = Seats::used($org->id);
        if ($new < $used) {
            throw ValidationException::withMessages([
                'seat_limit' => "This org already uses {$used} seats — remove members first.",
            ]);
        }

        $subscription = Subscription::where('org_id', $org->id)->firstOrFail();

        // Adjust the monthly price by the change in paid blocks (delta only, so we
        // never need to re-derive the feature price).
        $blockDelta = Seats::extraBlocks($new) - Seats::extraBlocks((int) $subscription->seat_limit);
        $subscription->monthly_price = round((float) $subscription->monthly_price + $blockDelta * Seats::BLOCK_PRICE, 2);
        $subscription->seat_limit = $new;
        $subscription->save();

        return response()->json(['data' => $this->subscriptionPayload($subscription->fresh())]);
    }

    /** POST /api/v1/admin/orgs/{org}/suspend — pause the org's access. */
    public function suspend(Org $org): JsonResponse
    {
        $subscription = Subscription::where('org_id', $org->id)->firstOrFail();
        $this->subscriptions->suspend($subscription);

        return response()->json(['data' => $this->subscriptionPayload($subscription->fresh())]);
    }

    /** POST /api/v1/admin/orgs/{org}/resume — un-pause WITHOUT charging (paid time must remain). */
    public function resume(Org $org): JsonResponse
    {
        $subscription = Subscription::where('org_id', $org->id)->firstOrFail();
        $this->subscriptions->resume($subscription);

        return response()->json(['data' => $this->subscriptionPayload($subscription->fresh())]);
    }

    /** GET /api/v1/admin/orgs/{org}/payments — payment history (for reversing). */
    public function payments(Org $org): JsonResponse
    {
        $subscription = Subscription::where('org_id', $org->id)->first();
        if (! $subscription) {
            return response()->json(['data' => []]);
        }

        $rows = SubscriptionPayment::query()
            ->where('subscription_id', $subscription->id)
            ->orderByDesc('id')
            ->get();
        $latestRecordedId = $rows->firstWhere('status', SubscriptionPayment::RECORDED)?->id;

        return response()->json([
            'data' => $rows->map(fn (SubscriptionPayment $p) => [
                'id' => $p->id,
                'months' => $p->months,
                'total_amount' => (float) $p->total_amount,
                'recorded_at' => $p->created_at?->toIso8601String(),
                'status' => $p->status,
                'reversed_at' => $p->reversed_at?->toIso8601String(),
                'can_reverse' => $p->id === $latestRecordedId,
            ]),
        ]);
    }

    /** POST /api/v1/admin/payments/{payment}/reverse — undo a mistaken/duplicate payment. */
    public function reversePayment(SubscriptionPayment $payment): JsonResponse
    {
        $this->subscriptions->reversePayment($payment, request()->user());

        return response()->json(['data' => ['reversed' => true]]);
    }

    /** POST /api/v1/admin/orgs/{org}/adjust — correct the paid-until date (fix a mistaken payment). */
    public function adjust(Request $request, Org $org): JsonResponse
    {
        $data = $request->validate(['current_period_end' => ['required', 'date']]);

        $subscription = Subscription::where('org_id', $org->id)->firstOrFail();
        $this->subscriptions->adjustPeriod($subscription, $data['current_period_end']);

        return response()->json(['data' => $this->subscriptionPayload($subscription->fresh())]);
    }

    /** @return array<string, mixed> */
    private function subscriptionPayload(Subscription $s): array
    {
        return [
            'plan' => $s->plan,
            'monthly_price' => (float) $s->monthly_price,
            'seat_limit' => (int) ($s->seat_limit ?? Seats::BASE),
            'seats_used' => Seats::used($s->org_id),
            'status' => $s->status,
            'is_active' => $s->isCurrentlyActive(),
            'current_period_end' => $s->current_period_end?->toIso8601String(),
        ];
    }
}
