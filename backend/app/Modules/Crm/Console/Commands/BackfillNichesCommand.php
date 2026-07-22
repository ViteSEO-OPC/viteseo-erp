<?php

namespace App\Modules\Crm\Console\Commands;

use App\Modules\Crm\Models\Lead;
use App\Modules\Crm\Models\Niche;
use Illuminate\Console\Command;

/**
 * One-shot registration of the Business Niche values already present on existing leads.
 *
 * Before the registry existed, `crm_leads.industry` was free text written by the create wizard, the
 * Edit Lead drawer, Excel import and scraper ingestion. This command reads every distinct value in
 * use and makes sure the registry knows about it, so no lead's existing niche silently disappears
 * from the selector the moment the feature ships.
 *
 * Per distinct value:
 *   - matches an approved built-in (case-insensitively) → left alone, counted as a built-in match;
 *   - already registered as a custom niche              → skipped as a duplicate;
 *   - otherwise                                         → registered as a CUSTOM niche.
 *
 * Leads are never modified — not one `industry` value is rewritten. This is registration only.
 *
 * ⚠️ Run AFTER NicheSeeder, once the canonical built-in list is approved. Running it first would
 * register every legacy value as custom, including the ones destined to be built-ins. (NicheSeeder
 * is idempotent and re-marks such rows as built-in, so that is recoverable, not fatal.)
 *
 * Idempotent — re-running reports everything as an already-registered duplicate and writes nothing.
 * Use --dry-run to produce the verification report without touching the database.
 */
class BackfillNichesCommand extends Command
{
    protected $signature = 'crm:backfill-niches {--dry-run : Report what would be registered without writing}';

    protected $description = 'Register the Business Niche values already used by existing leads into the niche registry';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        // Distinct niche values actually in use. Soft-deleted leads are INCLUDED: an archived lead
        // keeps its industry value and can be restored, so its niche must exist in the registry too.
        $values = Lead::query()
            ->withTrashed()
            ->whereNotNull('industry')
            ->where('industry', '!=', '')
            ->distinct()
            ->orderBy('industry')
            ->pluck('industry')
            ->all();

        // Lower-cased lookups so classification uses the same case-insensitive rule as the rest of
        // the feature (the registry's unique index, the industry filter, Niche::leadsMatching).
        $builtIns = Niche::query()->builtIn()->pluck('name')
            ->mapWithKeys(fn (string $n): array => [mb_strtolower($n) => $n])->all();
        $customs = Niche::query()->custom()->pluck('name')
            ->mapWithKeys(fn (string $n): array => [mb_strtolower($n) => $n])->all();

        $builtInMatches = [];
        $created = [];
        $skipped = [];
        // Two spellings of one niche ("Retail" / "retail") are one registry entry — track what this
        // run has already handled so the second spelling is reported as a duplicate, not created.
        $seen = [];

        foreach ($values as $value) {
            $name = trim((string) $value);
            $key = mb_strtolower($name);

            if ($name === '') {
                continue;
            }

            if (isset($seen[$key])) {
                $skipped[] = ['value' => $name, 'reason' => 'duplicate spelling of "'.$seen[$key].'"'];

                continue;
            }
            $seen[$key] = $name;

            if (isset($builtIns[$key])) {
                $builtInMatches[] = ['value' => $name, 'niche' => $builtIns[$key]];

                continue;
            }

            if (isset($customs[$key])) {
                $skipped[] = ['value' => $name, 'reason' => 'already registered as custom'];

                continue;
            }

            if (! $dryRun) {
                Niche::query()->create(['name' => $name, 'is_custom' => true]);
            }
            $customs[$key] = $name;
            $created[] = $name;
        }

        $this->report($values, $builtInMatches, $created, $skipped, $dryRun);

        return self::SUCCESS;
    }

    /**
     * Verification report (PO-requested): built-in matches, custom niches created, total processed,
     * and anything skipped or duplicated. Verification only — it changes no behaviour.
     *
     * @param list<string>                              $values
     * @param list<array{value: string, niche: string}> $builtInMatches
     * @param list<string>                              $created
     * @param list<array{value: string, reason: string}> $skipped
     */
    private function report(
        array $values,
        array $builtInMatches,
        array $created,
        array $skipped,
        bool $dryRun,
    ): void {
        $this->newLine();
        $this->info('Business Niche backfill'.($dryRun ? ' — DRY RUN (nothing written)' : ''));

        if (Niche::query()->builtIn()->doesntExist()) {
            $this->warn('No built-in niches are registered yet — every value below was treated as custom.');
            $this->warn('Run NicheSeeder with the approved canonical list first, then re-run this command.');
        }

        $this->newLine();
        $this->table(['Result', 'Count'], [
            ['Total distinct values processed', count($values)],
            ['Built-in matches', count($builtInMatches)],
            ['Custom niches created', count($created)],
            ['Skipped / duplicate', count($skipped)],
        ]);

        if ($builtInMatches !== []) {
            $this->newLine();
            $this->line('<comment>Built-in matches</comment> (already canonical — left unchanged):');
            foreach ($builtInMatches as $match) {
                $this->line(sprintf('  • %s → %s', $match['value'], $match['niche']));
            }
        }

        if ($created !== []) {
            $this->newLine();
            $this->line('<comment>Custom niches '.($dryRun ? 'that would be created' : 'created').'</comment>:');
            foreach ($created as $name) {
                $this->line('  • '.$name);
            }
        }

        if ($skipped !== []) {
            $this->newLine();
            $this->line('<comment>Skipped</comment>:');
            foreach ($skipped as $skip) {
                $this->line(sprintf('  • %s — %s', $skip['value'], $skip['reason']));
            }
        }

        $this->newLine();
        $this->line(sprintf(
            'Registry now holds %d built-in and %d custom niche(s). No lead records were modified.',
            Niche::query()->builtIn()->count(),
            Niche::query()->custom()->count(),
        ));
    }
}
