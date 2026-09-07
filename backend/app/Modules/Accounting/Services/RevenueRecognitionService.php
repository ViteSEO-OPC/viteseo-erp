<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Services;

use App\Models\User;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\RevenueSchedule;
use App\Modules\Accounting\Support\AccountingClock;
use App\Support\CurrentOrg;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Deferred-revenue recognition. When revenue is collected up front for several
 * months, the future months are held in Unearned Revenue (a liability) and moved
 * into revenue one month at a time — so each period's P&L shows only the revenue
 * it earned. All postings go through JournalService (balanced, immutable).
 */
class RevenueRecognitionService
{
    private const UNEARNED_CODE = '2210'; // Unearned Revenue (liability)
    private const MAX_CATCHUP = 60;

    public function __construct(
        private readonly JournalService $journal,
    ) {}

    /**
     * Defer the future-month portion of an up-front collection. The full amount
     * is assumed already credited to `$revenueAccount` (e.g. by the invoice);
     * this reclassifies month 2..N into Unearned Revenue and creates the schedule
     * that recognizes them monthly. No-op for a single month.
     */
    public function defer(
        string $description,
        float $monthlyAmount,
        int $months,
        Account $revenueAccount,
        User $actor,
        ?string $sourceType = null,
        ?int $sourceId = null,
    ): ?RevenueSchedule {
        if ($months <= 1 || $monthlyAmount <= 0) {
            return null;
        }

        $unearned = $this->unearnedAccount();
        $deferred = round($monthlyAmount * ($months - 1), 2);

        return DB::transaction(function () use ($description, $monthlyAmount, $months, $revenueAccount, $unearned, $deferred, $actor, $sourceType, $sourceId): RevenueSchedule {
            // Move months 2..N out of revenue and into the liability.
            $entry = $this->journal->create([
                'entry_date' => AccountingClock::today(),
                'reference' => 'DEFER',
                'memo' => "Deferred revenue — {$description}",
                'lines' => [
                    ['account_id' => $revenueAccount->id, 'debit' => $deferred],
                    ['account_id' => $unearned->id, 'credit' => $deferred],
                ],
            ]);
            $this->journal->post($entry, $actor);

            return RevenueSchedule::create([
                'description' => $description,
                'monthly_amount' => $monthlyAmount,
                'months' => $months,
                'recognized_count' => 1, // the current month stays recognized now
                'next_recognition_date' => AccountingClock::now()->addMonthNoOverflow()->toDateString(),
                'unearned_account_id' => $unearned->id,
                'revenue_account_id' => $revenueAccount->id,
                'status' => RevenueSchedule::ACTIVE,
                'source_type' => $sourceType,
                'source_id' => $sourceId,
            ]);
        });
    }

    /**
     * Recognize every month due (up to `asOf`, default today) for the current
     * org's active schedules — moving one month at a time from Unearned Revenue
     * back into revenue. Returns the number of monthly recognitions posted.
     */
    public function recognizeDue(User $actor, ?string $asOf = null): int
    {
        $today = $asOf ? Carbon::parse($asOf)->endOfDay() : Carbon::parse(AccountingClock::today())->endOfDay();

        $schedules = RevenueSchedule::query()
            ->where('status', RevenueSchedule::ACTIVE)
            ->whereDate('next_recognition_date', '<=', $today)
            ->get();

        $count = 0;
        foreach ($schedules as $schedule) {
            $count += $this->recognizeSchedule($schedule, $today, $actor);
        }

        return $count;
    }

    private function recognizeSchedule(RevenueSchedule $schedule, Carbon $asOf, User $actor): int
    {
        return DB::transaction(function () use ($schedule, $asOf, $actor): int {
            $schedule = RevenueSchedule::query()->whereKey($schedule->id)->lockForUpdate()->first();
            if ($schedule === null || $schedule->status !== RevenueSchedule::ACTIVE) {
                return 0;
            }

            $count = 0;
            while ($count < self::MAX_CATCHUP) {
                $due = $schedule->next_recognition_date;
                if ($due === null || $due->gt($asOf) || $schedule->remaining() <= 0) {
                    break;
                }

                $entry = $this->journal->create([
                    'entry_date' => $due->toDateString(),
                    'reference' => 'REVREC',
                    'memo' => "Revenue recognized — {$schedule->description}",
                    'lines' => [
                        ['account_id' => $schedule->unearned_account_id, 'debit' => (float) $schedule->monthly_amount],
                        ['account_id' => $schedule->revenue_account_id, 'credit' => (float) $schedule->monthly_amount],
                    ],
                ]);
                $this->journal->post($entry, $actor);
                $count++;

                $recognized = $schedule->recognized_count + 1;
                $done = $recognized >= $schedule->months;
                $schedule->update([
                    'recognized_count' => $recognized,
                    'status' => $done ? RevenueSchedule::COMPLETED : RevenueSchedule::ACTIVE,
                    'next_recognition_date' => $done ? null : $due->copy()->addMonthNoOverflow(),
                ]);

                if ($done) {
                    break;
                }
            }

            return $count;
        });
    }

    /** The org's Unearned Revenue account, created if the COA predates it. */
    private function unearnedAccount(): Account
    {
        return Account::query()->firstOrCreate(
            ['code' => self::UNEARNED_CODE],
            ['name' => 'Unearned Revenue', 'type' => 'liability', 'is_active' => true],
        );
    }
}
