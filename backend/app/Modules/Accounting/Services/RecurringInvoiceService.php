<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Services;

use App\Models\User;
use App\Modules\Accounting\Models\Invoice;
use App\Modules\Accounting\Models\RecurringInvoice;
use App\Modules\Accounting\Support\AccountingClock;
use App\Support\CurrentOrg;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Manages recurring-invoice schedules and generates the real invoices they're
 * due for. Generation reuses InvoiceService so every produced invoice posts the
 * normal Dr A/R / Cr Income entry — no special-casing downstream.
 */
class RecurringInvoiceService
{
    /** Safety cap on catch-up generations per schedule in a single run. */
    private const MAX_CATCHUP = 60;

    public function __construct(
        private readonly InvoiceService $invoices,
    ) {}

    /**
     * Create a schedule + its line template. `next_run_date` starts at
     * `start_date` (so a start date of today generates on the next run).
     *
     * @param  array{contact_id:int, description?:string|null, frequency:string, tax_rate?:float, vat_class?:string, due_days?:int, start_date:string, end_date?:string|null, lines:array<int,array{description?:string|null,quantity?:float,unit_price?:float,account_id:int}>}  $data
     */
    public function create(array $data): RecurringInvoice
    {
        return DB::transaction(function () use ($data): RecurringInvoice {
            $schedule = RecurringInvoice::create([
                'contact_id' => $data['contact_id'],
                'description' => $data['description'] ?? null,
                'frequency' => $data['frequency'],
                'tax_rate' => $data['tax_rate'] ?? 0,
                'vat_class' => $data['vat_class'] ?? 'vatable',
                'due_days' => $data['due_days'] ?? 0,
                'start_date' => $data['start_date'],
                'next_run_date' => $data['start_date'],
                'end_date' => $data['end_date'] ?? null,
                'status' => RecurringInvoice::ACTIVE,
            ]);

            foreach ($data['lines'] as $line) {
                $schedule->lines()->create([
                    'description' => $line['description'] ?? null,
                    'quantity' => $line['quantity'] ?? 1,
                    'unit_price' => $line['unit_price'] ?? 0,
                    'account_id' => (int) $line['account_id'],
                ]);
            }

            return $schedule->load('lines');
        });
    }

    /**
     * How many invoices a schedule with this start_date/frequency would back-
     * generate on its first run (capped at MAX_CATCHUP) — used to warn before a
     * far-back start_date silently posts a pile of back-dated invoices.
     */
    public function pendingCatchupCount(string $startDate, string $frequency, ?string $asOf = null): int
    {
        $today = $asOf ? Carbon::parse($asOf)->endOfDay() : Carbon::parse(AccountingClock::today())->endOfDay();
        $run = Carbon::parse($startDate)->startOfDay();

        $count = 0;
        while ($count < self::MAX_CATCHUP && ! $run->gt($today)) {
            $count++;
            $run = $this->advance($run, $frequency);
        }

        return $count;
    }

    public function pause(RecurringInvoice $schedule): RecurringInvoice
    {
        if ($schedule->status === RecurringInvoice::ACTIVE) {
            $schedule->update(['status' => RecurringInvoice::PAUSED]);
        }

        return $schedule;
    }

    public function resume(RecurringInvoice $schedule): RecurringInvoice
    {
        if ($schedule->status === RecurringInvoice::PAUSED) {
            $schedule->update(['status' => RecurringInvoice::ACTIVE]);
        }

        return $schedule;
    }

    /**
     * Generate every invoice due (up to `asOf`, default today) for the CURRENT
     * org's active schedules. Catches up multiple missed periods per schedule.
     * Returns the number of invoices generated.
     */
    public function generateDue(User $actor, ?string $asOf = null): int
    {
        $today = $asOf ? Carbon::parse($asOf)->endOfDay() : Carbon::parse(AccountingClock::today())->endOfDay();

        $schedules = RecurringInvoice::query()
            ->where('status', RecurringInvoice::ACTIVE)
            ->whereDate('next_run_date', '<=', $today)
            ->with('lines')
            ->get();

        $generated = 0;
        foreach ($schedules as $schedule) {
            $generated += $this->runSchedule($schedule, $today, $actor);
        }

        return $generated;
    }

    /** Generate all due periods for one schedule; advance / end it as needed. */
    private function runSchedule(RecurringInvoice $schedule, Carbon $asOf, User $actor): int
    {
        return DB::transaction(function () use ($schedule, $asOf, $actor): int {
            // Lock the schedule row so a concurrent "run now" + daily cron can't
            // both generate the same period (double-posting the invoice).
            $locked = RecurringInvoice::query()->whereKey($schedule->id)->lockForUpdate()->first();
            if ($locked === null || $locked->status !== RecurringInvoice::ACTIVE) {
                return 0;
            }
            $locked->loadMissing('lines');

            return $this->runScheduleLocked($locked, $asOf, $actor);
        });
    }

    /** The generation loop, run while holding the schedule's row lock. */
    private function runScheduleLocked(RecurringInvoice $schedule, Carbon $asOf, User $actor): int
    {
        $count = 0;

        while ($count < self::MAX_CATCHUP) {
            $runDate = $schedule->next_run_date;
            if ($runDate === null || $runDate->gt($asOf)) {
                break;
            }
            if ($schedule->end_date !== null && $runDate->gt($schedule->end_date)) {
                $schedule->update(['status' => RecurringInvoice::ENDED]);
                break;
            }

            $this->generateInvoice($schedule, $runDate, $actor);
            $count++;

            $next = $this->advance($runDate, $schedule->frequency);
            $ended = $schedule->end_date !== null && $next->gt($schedule->end_date);
            $schedule->update([
                'next_run_date' => $next,
                'last_generated_at' => Carbon::now(),
                'occurrences' => $schedule->occurrences + 1,
                'status' => $ended ? RecurringInvoice::ENDED : RecurringInvoice::ACTIVE,
            ]);

            if ($ended) {
                break;
            }
        }

        return $count;
    }

    /** Build + post one invoice from the schedule's template. */
    private function generateInvoice(RecurringInvoice $schedule, Carbon $issueDate, User $actor): Invoice
    {
        $lines = $schedule->lines->map(fn ($l) => [
            'description' => $l->description,
            'quantity' => (float) $l->quantity,
            'unit_price' => (float) $l->unit_price,
            'account_id' => $l->account_id,
        ])->all();

        return $this->invoices->create([
            'contact_id' => $schedule->contact_id,
            'issue_date' => $issueDate->toDateString(),
            'due_date' => $issueDate->copy()->addDays($schedule->due_days)->toDateString(),
            'description' => $schedule->description,
            'tax_rate' => (float) $schedule->tax_rate,
            'vat_class' => $schedule->vat_class,
            'lines' => $lines,
        ], $actor);
    }

    /** Advance a date by one period of the given frequency. */
    private function advance(Carbon $date, string $frequency): Carbon
    {
        return match ($frequency) {
            'weekly' => $date->copy()->addWeek(),
            'quarterly' => $date->copy()->addMonthsNoOverflow(3),
            'yearly' => $date->copy()->addYearNoOverflow(),
            default => $date->copy()->addMonthNoOverflow(), // monthly
        };
    }
}
