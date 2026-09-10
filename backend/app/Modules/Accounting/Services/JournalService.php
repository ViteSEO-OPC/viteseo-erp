<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Services;

use App\Models\User;
use App\Modules\Accounting\Enums\JournalSourceType;
use App\Modules\Accounting\Enums\JournalStatus;
use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Accounting\Support\AccountingClock;
use App\Modules\Accounting\Support\CurrentOrg;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class JournalService
{
    /**
     * Create a (draft) journal entry from validated data. The WHOLE entry is
     * rejected unless total debits exactly equal total credits and there is at
     * least one positive amount — the core rule of double-entry bookkeeping.
     *
     * @param  array{entry_date:string, reference?:?string, memo?:?string, source_type?:string, source_id?:?int, lines: array<int, array<string, mixed>>}  $data
     */
    public function create(array $data): JournalEntry
    {
        $lines = $data['lines'];
        $this->assertBalanced($lines);

        return DB::transaction(function () use ($data, $lines): JournalEntry {
            $entry = JournalEntry::create([
                'org_id' => CurrentOrg::id(),
                'entry_date' => $data['entry_date'],
                'reference' => $data['reference'] ?? null,
                'memo' => $data['memo'] ?? null,
                'source_type' => $data['source_type'] ?? 'manual',
                'source_id' => $data['source_id'] ?? null,
                'status' => JournalStatus::Draft,
            ]);

            foreach ($lines as $line) {
                $entry->lines()->create([
                    'account_id' => $line['account_id'],
                    'debit' => $line['debit'] ?? 0,
                    'credit' => $line['credit'] ?? 0,
                    'memo' => $line['memo'] ?? null,
                ]);
            }

            return $entry->load('lines');
        });
    }

    /** Post a draft entry. Once posted it is immutable — fixes use a reversing entry. */
    public function post(JournalEntry $entry, User $user): JournalEntry
    {
        if ($entry->isPosted()) {
            return $entry;
        }

        $this->assertBalanced(
            $entry->lines->map(fn ($l): array => ['debit' => $l->debit, 'credit' => $l->credit])->all()
        );

        $entry->update([
            'status' => JournalStatus::Posted,
            'posted_by' => $user->id,
            'posted_at' => now(),
        ]);

        return $entry->refresh()->load('lines');
    }

    /**
     * Reverse a posted entry by posting a mirror entry with debits/credits
     * swapped. The original stays (immutable); the two net to zero.
     */
    public function reverse(JournalEntry $entry, User $user): JournalEntry
    {
        if (! $entry->isPosted()) {
            throw ValidationException::withMessages([
                'entry' => 'Only posted entries can be reversed.',
            ]);
        }

        if ($entry->isReversed()) {
            throw ValidationException::withMessages([
                'entry' => 'This entry has already been reversed.',
            ]);
        }

        $entry->loadMissing('lines');

        return DB::transaction(function () use ($entry, $user): JournalEntry {
            $reversal = JournalEntry::create([
                'org_id' => $entry->org_id,
                'entry_date' => AccountingClock::today(),
                'reference' => 'REV-' . ($entry->reference ?? $entry->id),
                'memo' => 'Reversal of ' . ($entry->reference ?? ('entry #' . $entry->id)),
                'source_type' => JournalSourceType::Reversal,
                'source_id' => $entry->id,
                'status' => JournalStatus::Draft,
            ]);

            foreach ($entry->lines as $line) {
                $reversal->lines()->create([
                    'account_id' => $line->account_id,
                    'debit' => $line->credit, // swapped
                    'credit' => $line->debit,
                ]);
            }

            $posted = $this->post($reversal->load('lines'), $user);

            // Mark the original reversed so it can't be reversed twice (audit link).
            $entry->forceFill([
                'reversed_at' => now(),
                'reversal_entry_id' => $posted->id,
            ])->save();

            return $posted;
        });
    }

    /**
     * Trial balance for the current org: total debit/credit per account across
     * POSTED entries. In a balanced ledger, grand-total debits == grand-total credits.
     *
     * @return array<int, array{account_id:int, code:string, name:string, type:string, debit:float, credit:float}>
     */
    public function trialBalance(): array
    {
        return DB::table('acc_journal_lines as l')
            ->join('acc_journal_entries as e', 'e.id', '=', 'l.journal_entry_id')
            ->join('acc_accounts as a', 'a.id', '=', 'l.account_id')
            ->where('e.org_id', CurrentOrg::id())
            ->where('e.status', JournalStatus::Posted->value)
            ->whereNull('e.deleted_at')
            ->groupBy('a.id', 'a.code', 'a.name', 'a.type')
            ->orderByRaw("CASE a.type WHEN 'asset' THEN 1 WHEN 'liability' THEN 2 WHEN 'equity' THEN 3 WHEN 'income' THEN 4 WHEN 'expense' THEN 5 ELSE 6 END")
            ->orderBy('a.code')
            ->selectRaw('a.id as account_id, a.code, a.name, a.type, SUM(l.debit) as debit, SUM(l.credit) as credit')
            ->get()
            ->map(fn ($r): array => [
                'account_id' => (int) $r->account_id,
                'code' => $r->code,
                'name' => $r->name,
                'type' => $r->type,
                'debit' => (float) $r->debit,
                'credit' => (float) $r->credit,
            ])->all();
    }

    /**
     * @param  array<int, array<string, mixed>>  $lines
     */
    private function assertBalanced(array $lines): void
    {
        $debit = 0.0;
        $credit = 0.0;
        foreach ($lines as $line) {
            $debit += (float) ($line['debit'] ?? 0);
            $credit += (float) ($line['credit'] ?? 0);
        }

        if (round($debit, 2) <= 0.0) {
            throw ValidationException::withMessages([
                'lines' => 'A journal entry must have at least one positive amount.',
            ]);
        }

        // Balanced within half a centavo (avoid fragile float equality).
        if (abs($debit - $credit) >= 0.005) {
            throw ValidationException::withMessages([
                'lines' => sprintf(
                    'Debits (%s) must equal credits (%s).',
                    number_format($debit, 2),
                    number_format($credit, 2),
                ),
            ]);
        }
    }
}
