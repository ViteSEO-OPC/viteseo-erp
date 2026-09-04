<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Org;
use App\Models\Subscription;
use App\Models\SubscriptionPayment;
use App\Models\User;

/**
 * Subscription billing, MANUAL.
 *
 * A client says what they want, the platform owner sets it up and records what
 * was paid. The row in `subscription_payments` IS the record -- who paid, how
 * many months, what it moved the period to, and who recorded it -- and
 * SubscriptionService writes it either way.
 *
 * WHAT THIS CLASS USED TO DO, AND WHY IT NO LONGER DOES. It posted every
 * subscription payment into the provider org's own books through the Accounting
 * module: a fully-paid invoice, a cash/revenue journal entry, and a deferred-
 * revenue schedule so a multi-month prepayment only hit the month it earned.
 * That was good bookkeeping and it dogfooded our own product. It is gone because
 * Accounting is Kiplo's product, not ViteSEO's, and it does not live in this
 * repo any more.
 *
 * The seams are kept rather than deleted: the two methods still exist with the
 * same signatures, so SubscriptionService did not have to change and the day
 * automated billing comes back -- through whatever books ViteSEO then keeps --
 * there is one file to fill in rather than a call site to rediscover. They were
 * already documented as best-effort and already returned nulls when the provider
 * had no accounting set up, so a null here is a state the callers have always
 * handled.
 */
class SubscriptionBillingService
{
    /** The provider (biller) org — the platform owner (first org). */
    public function providerOrg(): ?Org
    {
        return Org::orderBy('id')->first();
    }

    /**
     * No books to post to. The payment is still recorded by the caller.
     *
     * @return array{invoice: null, schedule: null}
     */
    public function recordSubscriptionPayment(Subscription $subscription, User $actor, int $months = 1): array
    {
        return ['invoice' => null, 'schedule' => null];
    }

    /**
     * Nothing to unpost. The caller still rewinds the access period and marks the
     * payment reversed, which is the whole of what a manual reversal means.
     */
    public function reversePaymentAccounting(SubscriptionPayment $payment, User $actor): void
    {
        //
    }
}
