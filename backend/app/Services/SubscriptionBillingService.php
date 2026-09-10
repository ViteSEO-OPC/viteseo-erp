<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Org;
use App\Models\Subscription;
use App\Models\SubscriptionPayment;
use App\Models\User;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\Contact;
use App\Modules\Accounting\Models\Invoice;
use App\Modules\Accounting\Models\RevenueSchedule;
use App\Modules\Accounting\Services\InvoiceService;
use App\Modules\Accounting\Services\JournalService;
use App\Modules\Accounting\Services\RevenueRecognitionService;
use App\Support\CurrentOrg;

/**
 * Records subscription revenue in the PROVIDER org's books (the platform owner =
 * the first org) by dogfooding the accounting module ViteSEO sells. Prepaid
 * model: revenue is booked only when a customer PAYS — as a fully-PAID invoice
 * (cash in, no receivable), never an outstanding AR. A multi-month prepayment is
 * deferred so each month's P&L only shows the month it earned.
 *
 * HISTORY. This was emptied to two stubs on 2026-09-03, when Accounting was
 * believed to be another product and left this repo; the seams were kept rather
 * than deleted, so the call sites in SubscriptionService never changed. It came
 * back on 2026-09-07, by the owner's decision, after Accounting returned on
 * 2026-09-05 — one file filled in, exactly as the note left behind said it would
 * be. Until then `revenueSchedules` was empty for the provider: not because
 * deferred revenue was unavailable, but because nothing created one.
 *
 * BEST-EFFORT ON PURPOSE. Every path here returns nulls rather than throwing.
 * Recording that a client paid is the operator's job and it must not fail
 * because the provider's own books are not set up — the payment row is the
 * record, and the ledger entry is bookkeeping on top of it.
 */
class SubscriptionBillingService
{
    public function __construct(
        private readonly InvoiceService $invoices,
        private readonly RevenueRecognitionService $revenue,
        private readonly JournalService $journal,
    ) {}

    /** The provider (biller) org — the platform owner (first org). */
    public function providerOrg(): ?Org
    {
        return Org::orderBy('id')->first();
    }

    /**
     * Book a subscription payment in the provider's books as a PAID invoice for
     * the period (monthly price × months). Returns the invoice + any deferred-
     * revenue schedule created (both may be null if the provider isn't set up for
     * accounting) — best-effort, never throws billing failures onto the caller.
     *
     * @return array{invoice: ?Invoice, schedule: ?RevenueSchedule}
     */
    public function recordSubscriptionPayment(Subscription $subscription, User $actor, int $months = 1): array
    {
        $provider = $this->providerOrg();
        $customer = Org::find($subscription->org_id);
        if (! $provider || ! $customer) {
            return ['invoice' => null, 'schedule' => null];
        }

        try {
            return CurrentOrg::runAs($provider->id, function () use ($customer, $subscription, $actor, $months): array {
                $incomeAccount = Account::query()->where('code', '4000')->first(); // Service Revenue
                if (! $incomeAccount) {
                    return ['invoice' => null, 'schedule' => null];
                }

                // Key the billing contact by the customer ORG, not its name, so
                // two customers with the same name don't share one contact.
                $contact = Contact::query()->where('source_org_id', $customer->id)->first()
                    ?? Contact::create([
                        'source_org_id' => $customer->id,
                        'name' => $customer->name,
                        'type' => 'customer',
                    ]);

                $invoice = $this->invoices->create([
                    'contact_id' => $contact->id,
                    'issue_date' => now()->toDateString(),
                    'due_date' => now()->toDateString(),
                    'description' => "Subscription — {$subscription->plan} plan ({$months} mo)",
                    'tax_rate' => 0,
                    'lines' => [[
                        'description' => "Subscription ({$subscription->plan}) — monthly",
                        'quantity' => $months,
                        'unit_price' => (float) $subscription->monthly_price,
                        'account_id' => $incomeAccount->id,
                    ]],
                ], $actor);

                // Prepaid: mark the invoice fully paid right away (no receivable).
                $paid = $this->invoices->recordPayment($invoice, (float) $invoice->amount, $actor);

                // Multi-month prepayment: hold months 2..N in Unearned Revenue and
                // recognize them monthly (so each period's P&L is accurate).
                $schedule = $months > 1
                    ? $this->revenue->defer(
                        "Subscription ({$subscription->plan}) — {$customer->name}",
                        (float) $subscription->monthly_price,
                        $months,
                        $incomeAccount,
                        $actor,
                        'subscription',
                        $subscription->id,
                    )
                    : null;

                return ['invoice' => $paid, 'schedule' => $schedule];
            });
        } catch (\Throwable $e) {
            report($e);

            return ['invoice' => null, 'schedule' => null];
        }
    }

    /**
     * Reverse a recorded payment in the provider's books — one balanced
     * compensating entry that undoes the cash + revenue (and any still-deferred
     * amount): Dr Service Revenue (recognized), Dr Unearned Revenue (remaining),
     * Cr Cash (total). Marks the revenue schedule reversed so it stops recognizing.
     */
    public function reversePaymentAccounting(SubscriptionPayment $payment, User $actor): void
    {
        $provider = $this->providerOrg();
        if (! $provider) {
            return;
        }

        try {
            CurrentOrg::runAs($provider->id, function () use ($payment, $actor): void {
                $cash = Account::query()->where('code', '1000')->first();
                $revenue = Account::query()->where('code', '4000')->first();
                $unearned = Account::query()->where('code', '2210')->first();
                if (! $cash || ! $revenue) {
                    return;
                }

                $total = (float) $payment->total_amount;
                $recognizedAmt = $total;
                $unearnedAmt = 0.0;

                // If deferred, split the reversal between recognized revenue and
                // the amount still sitting in Unearned, based on the schedule now.
                $schedule = $payment->revenue_schedule_id
                    ? RevenueSchedule::query()->find($payment->revenue_schedule_id)
                    : null;
                if ($schedule && $unearned) {
                    $recognizedAmt = round((float) $schedule->monthly_amount * $schedule->recognized_count, 2);
                    $unearnedAmt = round((float) $schedule->monthly_amount * $schedule->remaining(), 2);
                }

                $lines = [['account_id' => $revenue->id, 'debit' => $recognizedAmt]];
                if ($unearnedAmt > 0 && $unearned) {
                    $lines[] = ['account_id' => $unearned->id, 'debit' => $unearnedAmt];
                }
                $lines[] = ['account_id' => $cash->id, 'credit' => round($recognizedAmt + $unearnedAmt, 2)];

                $entry = $this->journal->create([
                    'entry_date' => now()->toDateString(),
                    'reference' => 'REFUND',
                    'memo' => "Reversed subscription payment #{$payment->id}",
                    'lines' => $lines,
                ]);
                $this->journal->post($entry, $actor);

                if ($schedule && $schedule->status !== RevenueSchedule::REVERSED) {
                    $schedule->update(['status' => RevenueSchedule::REVERSED, 'next_recognition_date' => null]);
                }
            });
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
