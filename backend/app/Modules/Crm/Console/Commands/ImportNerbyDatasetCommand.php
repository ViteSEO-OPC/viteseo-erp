<?php

namespace App\Modules\Crm\Console\Commands;

use App\Models\Org;
use App\Models\User;
use App\Modules\Crm\Services\Discovery\NerbyDatasetImporter;
use App\Modules\Crm\Services\Discovery\NerbyServiceIdentity;
use Illuminate\Console\Command;

/**
 * Import an existing NERBY dataset (JSONL) into ONE org, without calling any discovery provider.
 *
 * OPERATOR-ONLY, BY DESIGN. There is no HTTP route for this: an offline dataset cannot carry the
 * per-run Google attestation the HTTP ingest demands, so it may only be landed by someone with shell
 * access to the server. See {@see NerbyDatasetImporter} for what is copied and what is never touched.
 *
 * DRY RUN BY DEFAULT. A bare run validates the whole file, reports what WOULD be imported and writes
 * only the manifest. Writing needs `--confirm=<sha256>`, and that value must be the SHA-256 of the
 * exact file being read — the hash the dry run printed — so a confirmation cannot be carried over to a
 * file that changed after it was reviewed.
 *
 * `--org` is required and never inferred: a console command has no user, and guessing a tenant is the
 * bug CurrentOrg exists to stop. `created_by` on new leads is the NERBY service account (as for every
 * lead a live discovery run lands) unless `--actor` names a member of the target org.
 */
class ImportNerbyDatasetCommand extends Command
{
    protected $signature = 'crm:nerby-import
        {path : JSONL dataset, one discovered business per line}
        {--org= : Target org id (required)}
        {--source= : Comma-separated provider sources to import: google_places, openstreetmap, openai (required)}
        {--ai-assisted=any : discovery_ai_assisted filter: any, true, false or null}
        {--actor= : User id recorded as created_by (default: the NERBY service account)}
        {--manifest= : Where to write the manifest JSON (default: storage/app/private/nerby-imports/)}
        {--confirm= : SHA-256 of the input file. Without it the command is a dry run and writes no leads}';

    protected $description = 'Dry-run (or, with --confirm=<sha256>, import) an existing NERBY JSONL dataset into one org';

    public function handle(NerbyDatasetImporter $importer, NerbyServiceIdentity $identity): int
    {
        $path = (string) $this->argument('path');
        $orgOption = $this->option('org');
        $sourceOption = $this->option('source');
        $confirm = $this->option('confirm');
        $write = $confirm !== null && $confirm !== '';

        if ($orgOption === null || ! ctype_digit((string) $orgOption) || ! Org::query()->whereKey((int) $orgOption)->exists()) {
            $this->error('--org is required and must be an existing org id.');

            return self::INVALID;
        }
        $orgId = (int) $orgOption;

        if ($sourceOption === null || trim((string) $sourceOption) === '') {
            $this->error('--source is required ('.implode(', ', NerbyDatasetImporter::sources()).').');

            return self::INVALID;
        }
        $sources = array_values(array_unique(array_filter(array_map('trim', explode(',', (string) $sourceOption)))));
        $unknown = array_diff($sources, NerbyDatasetImporter::sources());
        if ($unknown !== []) {
            $this->error('Unknown source(s): '.implode(', ', $unknown).'. discovery_ai_assisted is a separate filter (--ai-assisted), not a source.');

            return self::INVALID;
        }

        $aiFilter = strtolower((string) $this->option('ai-assisted'));
        if (! in_array($aiFilter, NerbyDatasetImporter::AI_FILTERS, true)) {
            $this->error('--ai-assisted must be one of: '.implode(', ', NerbyDatasetImporter::AI_FILTERS).'.');

            return self::INVALID;
        }

        if (! is_file($path) || ! is_readable($path)) {
            $this->error("Input file is not readable: {$path}");

            return self::INVALID;
        }

        // The actor is resolved only for a write: a dry run must not even provision the service account.
        $actorId = null;
        if ($write) {
            $actorOption = $this->option('actor');
            if ($actorOption !== null) {
                $actor = ctype_digit((string) $actorOption) ? User::query()->find((int) $actorOption) : null;
                if ($actor === null || ! $actor->orgs()->whereKey($orgId)->exists()) {
                    $this->error('--actor must be the id of a user who belongs to the target org.');

                    return self::INVALID;
                }
                $actorId = (int) $actor->getKey();
            } else {
                $actorId = (int) $identity->user()->getKey();
            }
        }

        $manifest = $importer->run($path, $orgId, $sources, $aiFilter, $write ? (string) $confirm : null, $actorId);
        $manifestPath = $importer->storeManifest($manifest, $this->option('manifest'));

        $this->report($manifest, $manifestPath);

        return $manifest['status'] === 'ok' ? self::SUCCESS : self::FAILURE;
    }

    /** @param  array<string, mixed>  $manifest */
    private function report(array $manifest, string $manifestPath): void
    {
        $dry = $manifest['mode'] === 'dry_run';
        $counts = $manifest['counts'];

        $this->newLine();
        $this->info('NERBY dataset import — '.($dry ? 'DRY RUN (no leads written)' : 'WRITE'));
        $this->line('  file     '.$manifest['input']['file'].' ('.$manifest['input']['lines'].' lines)');
        $this->line('  sha256   '.$manifest['input']['sha256']);
        $this->line('  org      '.$manifest['target']['org_id']);
        $this->line('  filters  source='.implode(',', $manifest['filters']['sources']).' ai-assisted='.$manifest['filters']['discovery_ai_assisted']);

        $this->table(['total', 'valid', 'rejected', 'filtered', 'skipped', $dry ? 'would import' : 'eligible', 'imported'], [[
            $counts['total'], $counts['valid'], $counts['rejected'], $counts['filtered'], $counts['skipped'],
            $counts['eligible'], $counts['imported'],
        ]]);

        $reasons = [];
        foreach ($manifest['reasons'] as $bucket => $byReason) {
            foreach ($byReason as $reason => $entry) {
                $reasons[] = [$bucket, $reason, $entry['count'], implode(',', array_slice($entry['lines'], 0, 10))];
            }
        }
        if ($reasons !== []) {
            $this->table(['bucket', 'reason', 'count', 'first lines'], $reasons);
        }
        if ($manifest['dropped_fields'] !== []) {
            $this->line('  dropped fields (never copied): '.implode(', ', array_map(
                fn (string $k, int $n): string => "{$k}×{$n}",
                array_keys($manifest['dropped_fields']),
                $manifest['dropped_fields'],
            )));
        }

        if ($manifest['status'] !== 'ok') {
            $this->error(strtoupper((string) $manifest['status']).': '.$manifest['error']);
        } elseif ($dry) {
            $this->line('  To write, re-run with --confirm='.$manifest['input']['sha256']);
        }
        $this->line('  manifest '.$manifestPath);
    }
}
