<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Subscription;
use App\Models\SubscriptionPayment;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * Prepaid-suspend lifecycle for subscriptions. A customer pays for a period and
 * gets access until `current_period_end`; once that passes with no renewal the
 * subscription is suspended (access paused) until they pay again. No receivable
 * ever accrues — you either paid (active) or you didn't (suspended).
 */
class SubscriptionService
{
    public function __construct(
        private readonly SubscriptionBillingService $billing,
    ) {}

    /**
     * Record a payment for one or more months: (re)activate and extend the paid
     * period. Early renewals stack onto the remaining time; renewing after a
     * lapse starts a fresh period from now. Best-effort records the provider's
     * revenue as a PAID invoice (no receivable).
     */
    public function activate(Subscription $subscription, int $months = 1, ?User $actor = null): Subscription
    {
        $periodBefore = $subscription->current_period_end;
        $statusBefore = $subscription->status;

        // Stack onto any remaining time (paid OR trial), else start fresh from now.
        $start = ($periodBefore && $periodBefore->isFuture()) ? $periodBefore : Carbon::now();

        $subscription->status = Subscription::ACTIVE;
        $subscription->current_period_end = $start->copy()->addMonthsNoOverflow($months);
        $subscription->save();

        if ($actor) {
            $result = $this->billing->recordSubscriptionPayment($subscription, $actor, $months);

            // Log the payment so it can be reversed (undo a mistaken/duplicate one).
            SubscriptionPayment::create([
                'org_id' => $this->billing->providerOrg()?->id ?? $subscription->org_id,
                'subscription_id' => $subscription->id,
                'customer_org_id' => $subscription->org_id,
                'months' => $months,
                'monthly_amount' => $subscription->monthly_price,
                'total_amount' => round((float) $subscription->monthly_price * $months, 2),
                'period_before' => $periodBefore,
                'period_after' => $subscription->current_period_end,
                'status_before' => $statusBefore,
                'invoice_id' => $result['invoice']?->id,
                'revenue_schedule_id' => $result['schedule']?->id,
                'status' => SubscriptionPayment::RECORDED,
                'recorded_by' => $actor->id,
            ]);
        }

        return $subscription;
    }

    /**
     * Reverse a recorded payment — the undo for a mistaken/duplicate charge:
     * reverses the accounting in the provider's books and rolls the access period
     * back to what it was before the payment. Only the most recent (un-reversed)
     * payment can be reversed.
     */
    public function reversePayment(SubscriptionPayment $payment, User $actor): SubscriptionPayment
    {
        if ($payment->status === SubscriptionPayment::REVERSED) {
            throw ValidationException::withMessages(['status' => 'This payment is already reversed.']);
        }

        $latest = SubscriptionPayment::query()
            ->where('subscription_id', $payment->subscription_id)
            ->where('status', SubscriptionPayment::RECORDED)
            ->orderByDesc('id')
            ->first();
        if (! $latest || $latest->id !== $payment->id) {
            throw ValidationException::withMessages(['status' => 'Only the most recent payment can be reversed.']);
        }

        // Undo the books, then the access period.
        $this->billing->reversePaymentAccounting($payment, $actor);

        $subscription = Subscription::findOrFail($payment->subscription_id);
        $subscription->current_period_end = $payment->period_before;
        $subscription->status = $payment->status_before ?? Subscription::SUSPENDED;
        $subscription->save();
        $this->refreshStatus($subscription);

        $payment->update([
            'status' => SubscriptionPayment::REVERSED,
            'reversed_at' => Carbon::now(),
            'reversed_by' => $actor->id,
        ]);

        return $payment;
    }

    /**
     * Manually correct the paid-until date — for fixing a mistaken/duplicate
     * "record payment" (e.g. double-click). Sets current_period_end to the given
     * date and resolves status deterministically: a future date is active (a
     * trial stays trialing); a past/today date is suspended.
     */
    public function adjustPeriod(Subscription $subscription, string $endDate): Subscription
    {
        $end = Carbon::parse($endDate)->endOfDay();

        $subscription->current_period_end = $end;
        $subscription->status = $end->isFuture()
            ? ($subscription->status === Subscription::TRIALING ? Subscription::TRIALING : Subscription::ACTIVE)
            : Subscription::SUSPENDED;
        $subscription->save();

        return $subscription;
    }

    /** Pause access (manual, or on lapse). Keeps any remaining paid period. */
    public function suspend(Subscription $subscription): Subscription
    {
        $subscription->status = Subscription::SUSPENDED;
        $subscription->save();

        return $subscription;
    }

    /**
     * Un-pause a suspended subscription WITHOUT recording a payment — only valid
     * while it still has paid time left. If the period has lapsed, the caller
     * must record a payment instead (activate). Distinct from activate() so an
     * accidental "resume" never silently extends a customer's paid period.
     */
    public function resume(Subscription $subscription): Subscription
    {
        if ($subscription->status !== Subscription::SUSPENDED) {
            throw ValidationException::withMessages([
                'status' => 'Only a suspended subscription can be resumed.',
            ]);
        }

        $end = $subscription->current_period_end;
        if ($end === null || $end->isPast()) {
            throw ValidationException::withMessages([
                'status' => 'This subscription has expired — record a payment to reactivate.',
            ]);
        }

        $subscription->status = Subscription::ACTIVE;
        $subscription->save();

        return $subscription;
    }

    /**
     * Lazily flip an active-but-expired subscription to suspended, so status
     * reflects reality without needing a scheduled job. Returns the subscription.
     */
    public function refreshStatus(Subscription $subscription): Subscription
    {
        if (
            in_array($subscription->status, Subscription::ACCESS_STATUSES, true)
            && $subscription->current_period_end !== null
            && $subscription->current_period_end->isPast()
        ) {
            return $this->suspend($subscription);
        }

        return $subscription;
    }

    /** Is the org allowed into the product right now? (refreshes lapse first) */
    public function hasAccess(Subscription $subscription): bool
    {
        return $this->refreshStatus($subscription)->isCurrentlyActive();
    }
}
