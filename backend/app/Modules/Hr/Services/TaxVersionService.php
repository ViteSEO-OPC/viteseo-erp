<?php

namespace App\Modules\Hr\Services;

use App\Support\CurrentOrg;
use App\Modules\Hr\Models\TaxBracket;
use App\Modules\Hr\Models\TaxVersion;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * PUBLISHING A WITHHOLDING-TAX TABLE.
 *
 * The BIR schedule used to arrive by migration, which meant a rate change needed a developer
 * and a deploy. It is data now: HR types the published bands and saves, and the table is dated
 * TODAY — the same rule every other payroll setting follows, so a rate never reaches back into
 * a period that has already been priced.
 */
class TaxVersionService
{
    public function __construct(private readonly AuditService $audit) {}

    /**
     * Save the bands as the table in force from today. Saving twice in one day REPLACES the
     * day's table rather than stacking versions — the effective date is unique, and a second
     * save on the same day is a correction of the first, not a new schedule.
     *
     * @param  list<array{min_annual: float|int|string, base_tax: float|int|string, rate_on_excess: float|int|string}>  $bands
     */
    public function save(array $bands, ?string $note = null): TaxVersion
    {
        $today = now(config('app.timezone_local'))->toDateString();

        return DB::transaction(function () use ($bands, $note, $today) {
            $existing = TaxVersion::with('brackets')->whereDate('effective_from', $today)->first();
            // What payroll charged a moment ago — the table IN FORCE today, which on a first
            // publish is the one seeded by migration, not null. "What changed" is the only
            // question this audit line has to answer, and it has an answer either way.
            $inForce = TaxVersion::with('brackets')
                ->whereDate('effective_from', '<=', $today)
                ->orderByDesc('effective_from')
                ->orderByDesc('id')
                ->first();
            $before = $inForce ? $this->describe($inForce) : null;

            $version = TaxVersion::updateOrCreate(
                ['effective_from' => $today],
                ['note' => $note, 'created_by' => auth()->id()],
            );

            // Replaced wholesale, not diffed: a band that no longer exists in the published
            // table must not survive as a leftover row the resolver would still read.
            TaxBracket::where('tax_version_id', $version->id)->delete();

            /*
             * org_id by hand: Model::insert()/upsert() go straight to the query
             * builder, so BelongsToOrg's `creating` stamp never fires. Bulk writes
             * are the one path in this codebase that has to remember the tenant for
             * themselves, and forgetting is silent -- the row lands with a null org
             * and is then invisible to every scoped read that should have found it.
             */
            $orgId = CurrentOrg::id();

            TaxBracket::insert(array_map(fn (array $b) => [
                'org_id' => $orgId,
                'tax_version_id' => $version->id,
                'min_annual' => round((float) $b['min_annual'], 2),
                'base_tax' => round((float) $b['base_tax'], 2),
                'rate_on_excess' => round((float) $b['rate_on_excess'], 4),
                'created_at' => now(),
                'updated_at' => now(),
            ], $bands));

            $version->load('brackets');

            $this->audit->log(
                $existing === null ? 'created' : 'updated',
                'Payroll',
                ($existing === null
                    ? 'Published a withholding tax table'
                    : "Replaced today's withholding tax table")
                    ." effective {$today} — ".count($bands).' bands, exempt up to '
                    .number_format($this->exemptCeiling($bands), 2),
                $version,
                $before,
                $this->describe($version),
            );

            return $version;
        });
    }

    /**
     * Remove a published table. Refuses the last one: payroll would then resolve no bands and
     * withhold ₱0 from everybody, which looks exactly like a working run.
     */
    public function delete(TaxVersion $version): void
    {
        if (TaxVersion::whereKeyNot($version->id)->count() === 0) {
            throw ValidationException::withMessages([
                'version' => ['This is the only tax table. Removing it would withhold ₱0 on every '
                    .'payslip — publish a replacement first.'],
            ]);
        }

        $version->loadMissing('brackets');
        $before = $this->describe($version);
        $date = $version->effective_from?->toDateString();

        DB::transaction(function () use ($version) {
            TaxBracket::where('tax_version_id', $version->id)->delete();
            $version->delete();
        });

        $this->audit->log(
            'removed',
            'Payroll',
            "Removed the withholding tax table effective {$date}",
            null,
            $before,
            null,
        );
    }

    /**
     * The table as a plain array, for the audit trail's before/after. Rates as percentages,
     * because that is how the reader will compare them against the BIR schedule.
     *
     * @return array<string, mixed>
     */
    private function describe(TaxVersion $version): array
    {
        return [
            'note' => $version->note,
            'bands' => $version->brackets->map(fn (TaxBracket $b) => [
                'from' => (float) $b->min_annual,
                'base_tax' => (float) $b->base_tax,
                'rate_pct' => round((float) $b->rate_on_excess * 100, 2),
            ])->all(),
        ];
    }

    /**
     * Where tax starts — the floor of the first band charging anything. The one figure a
     * reader of the audit trail will actually recognise (₱250,000 today).
     *
     * @param  list<array<string, mixed>>  $bands
     */
    private function exemptCeiling(array $bands): float
    {
        foreach ($bands as $band) {
            if ((float) $band['rate_on_excess'] > 0) {
                return (float) $band['min_annual'];
            }
        }

        return 0.0;
    }
}
