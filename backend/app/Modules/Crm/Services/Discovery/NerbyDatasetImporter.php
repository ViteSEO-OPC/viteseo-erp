<?php

namespace App\Modules\Crm\Services\Discovery;

use App\Modules\Crm\Enums\NerbyProvider;
use App\Modules\Crm\Models\Lead;
use App\Modules\Crm\Requests\IngestDiscoveredLeadsRequest;
use App\Modules\Crm\Services\Discovery\Geo\PhilippinesBoundary;
use App\Modules\Crm\Services\LeadImportService;
use App\Support\CurrentOrg;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use RuntimeException;

/**
 * Lands an EXISTING NERBY dataset (JSONL, one discovered business per line) into one org — the
 * operator-only path behind `crm:nerby-import`. No provider is called: the file is the only input.
 *
 * WHY THIS IS NOT THE HTTP INGEST. `/leads/discovered` accepts a Google row only when the CRM itself
 * handed that place to the calling run's token (GooglePlacesAttestation). A dataset copied between
 * environments can never carry that proof, so this path exists ONLY as a console command run on the
 * server by an operator, and the attestation path is left exactly as it is.
 *
 * NEVER TOUCHES AN EXISTING LEAD. `LeadImportService::ingestDiscovered()` is reused unchanged for the
 * write — it is the boundary every discovered business crosses (fit-data allowlist, website/social
 * normalisation, national boundary, assigned_to = null, no score). But on a place_id it already holds
 * it proposes a website candidate, and for an AI row it records a discovery lane: both annotate an
 * EXISTING lead. So every row whose place_id (or email) the target org already holds — soft-deleted
 * leads included, so a deleted lead is never re-imported — is removed here first, and the ingest only
 * ever sees businesses the org has never had.
 *
 * VALIDATE EVERYTHING, THEN WRITE. The whole file is read and checked before anything is stored, and
 * a write is refused if ANY line was rejected, or if the file's SHA-256 is not the exact value the
 * operator confirmed. A dry run (the default) writes nothing but the manifest.
 *
 * `source` and `discovery_ai_assisted` are separate facts and separate filters: an AI-assisted Google
 * lead is still a Google Places lead.
 */
class NerbyDatasetImporter
{
    /** Businesses handed to the ingest per call — the same ceiling the HTTP ingest enforces. */
    public const CHUNK_SIZE = 500;

    /** How many example line numbers the manifest keeps per reason. */
    public const MAX_EXAMPLE_LINES = 50;

    public const AI_ANY = 'any';

    /** The AI-flag filter values: the column is tri-state, so "not recorded" is its own choice. */
    public const AI_FILTERS = [self::AI_ANY, 'true', 'false', 'null'];

    /**
     * The only fields copied from the file: factual provider data and provenance. Everything else —
     * local id, org_id, created_by, assigned_to, qualification, score, priority, status, timestamps,
     * website_status (which discovery may not decide) — is dropped and counted in the manifest.
     */
    public const FIELDS = [
        'business_name', 'contact_name', 'email', 'phone', 'industry',
        'region', 'province', 'city', 'latitude', 'longitude',
        'place_id', 'source', 'website', 'facebook', 'instagram',
        'discovery_ai_assisted',
    ];

    /** An OpenStreetMap element key — the identity OSM and AI-lane rows carry. */
    private const OSM_PLACE_ID = '#^(node|way|relation)/[1-9][0-9]{0,18}$#';

    private const GOOGLE_PLACE_ID = '#^places/\S+$#';

    private const LOCK_PREFIX = 'crm:nerby-import:org:';

    private const LOCK_SECONDS = 3600;

    public function __construct(
        private readonly LeadImportService $ingest,
        private readonly PhilippinesBoundary $philippines,
    ) {}

    /** @return list<string> */
    public static function sources(): array
    {
        return array_map(static fn (NerbyProvider $p): string => $p->value, NerbyProvider::cases());
    }

    /**
     * Read, validate, filter and (only with a matching confirmation) import one dataset.
     *
     * @param  list<string>  $sources  provider sources to import; each one of {@see sources()}
     * @param  string  $aiFilter  one of {@see AI_FILTERS}
     * @param  string|null  $confirmSha256  null = dry run; otherwise must equal the file's SHA-256
     * @param  int|null  $actorId  `created_by` for new leads; required when writing
     * @return array<string, mixed> the manifest
     */
    public function run(string $path, int $orgId, array $sources, string $aiFilter, ?string $confirmSha256, ?int $actorId): array
    {
        if (! is_file($path) || ! is_readable($path)) {
            throw new RuntimeException("Input file is not readable: {$path}");
        }
        $unknown = array_diff($sources, self::sources());
        if ($sources === [] || $unknown !== []) {
            throw new RuntimeException('Unknown source(s): '.implode(', ', $unknown ?: ['(none given)']));
        }
        if (! in_array($aiFilter, self::AI_FILTERS, true)) {
            throw new RuntimeException("Unknown discovery_ai_assisted filter: {$aiFilter}");
        }

        $sha256 = (string) hash_file('sha256', $path);
        $write = $confirmSha256 !== null;

        $manifest = [
            'tool' => 'crm:nerby-import',
            'manifest_version' => 1,
            'mode' => $write ? 'write' : 'dry_run',
            'status' => 'ok',
            'started_at' => now()->toIso8601String(),
            'finished_at' => null,
            'input' => [
                'file' => basename($path),
                'path' => realpath($path) ?: $path,
                'sha256' => $sha256,
                'bytes' => (int) filesize($path),
                'lines' => 0,
                // SHA-256 of the bytes the validation pass actually read; must equal `sha256`.
                'sha256_read' => null,
            ],
            'target' => ['org_id' => $orgId],
            'filters' => ['sources' => array_values($sources), 'discovery_ai_assisted' => $aiFilter],
            'actor_id' => $write ? $actorId : null,
            'counts' => [
                'total' => 0, 'valid' => 0, 'rejected' => 0, 'filtered' => 0, 'skipped' => 0,
                'eligible' => 0, 'imported' => 0,
            ],
            'reasons' => ['rejected' => [], 'filtered' => [], 'skipped' => []],
            'dropped_fields' => [],
            'ingest' => ['calls' => 0, 'valid' => 0, 'duplicates' => 0, 'rejected' => 0, 'website_candidates' => 0],
            'error' => null,
        ];

        // The confirmation is bound to the exact bytes that were dry-run, checked before anything else.
        if ($write && ! hash_equals($sha256, strtolower(trim($confirmSha256)))) {
            return $this->refuse($manifest, 'Confirmation SHA-256 does not match the input file; nothing was written.');
        }
        if ($write && $actorId === null) {
            return $this->refuse($manifest, 'A write needs an actor for created_by; nothing was written.');
        }

        return CurrentOrg::runAs($orgId, function () use ($path, $orgId, $sources, $aiFilter, $write, $actorId, $manifest): array {
            $rows = $this->readAndValidate($path, $manifest);

            // THE CONFIRMATION COVERS THE BYTES ACTUALLY READ. The hash above was taken before the
            // read; if the file changed in between, what was validated is not what was confirmed.
            if ($manifest['input']['sha256_read'] !== $manifest['input']['sha256']) {
                return $this->refuse($manifest, 'The input file changed while it was being read; nothing was written.');
            }

            $rows = $this->applyFilters($rows, $sources, $aiFilter, $manifest);
            $rows = $this->dropExisting($rows, $manifest);
            $manifest['counts']['eligible'] = count($rows);

            if (! $write) {
                return $this->finish($manifest);
            }
            if ($manifest['counts']['rejected'] > 0) {
                return $this->refuse($manifest, 'The input has rejected lines; fix the file before writing. Nothing was written.');
            }

            $lock = Cache::lock(self::LOCK_PREFIX.$orgId, self::LOCK_SECONDS);
            if (! $lock->get()) {
                return $this->refuse($manifest, 'Another NERBY import is running for this org; nothing was written.');
            }

            try {
                $this->write($rows, (int) $actorId, $manifest);
            } catch (\Throwable $e) {
                $manifest['status'] = 'failed';
                $manifest['error'] = $e->getMessage();

                return $this->finish($manifest);
            } finally {
                $lock->release();
            }

            return $this->finish($manifest);
        });
    }

    /**
     * Every line, checked. Returns the valid rows (allowlisted fields only) keyed by line number.
     *
     * @param  array<string, mixed>  $manifest
     * @return array<int, array<string, mixed>>
     */
    private function readAndValidate(string $path, array &$manifest): array
    {
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            throw new RuntimeException("Input file could not be opened: {$path}");
        }

        $rules = $this->rules();
        $valid = [];
        $line = 0;
        $hash = hash_init('sha256');

        try {
            while (($raw = fgets($handle)) !== false) {
                hash_update($hash, $raw);
                $line++;
                $manifest['counts']['total']++;
                $text = rtrim($raw, "\r\n");

                if (trim($text) === '') {
                    $this->count($manifest, 'rejected', 'blank_line', $line);

                    continue;
                }
                // Provider text is never re-encoded or repaired: a line that is not UTF-8 is refused.
                if (! mb_check_encoding($text, 'UTF-8')) {
                    $this->count($manifest, 'rejected', 'invalid_utf8', $line);

                    continue;
                }
                try {
                    $decoded = json_decode($text, true, 32, JSON_THROW_ON_ERROR);
                } catch (\JsonException) {
                    $this->count($manifest, 'rejected', 'invalid_json', $line);

                    continue;
                }
                if (! is_array($decoded) || array_is_list($decoded)) {
                    $this->count($manifest, 'rejected', 'not_an_object', $line);

                    continue;
                }

                foreach (array_keys($decoded) as $key) {
                    if (! in_array($key, self::FIELDS, true)) {
                        $manifest['dropped_fields'][$key] = ($manifest['dropped_fields'][$key] ?? 0) + 1;
                    }
                }
                $row = array_intersect_key($decoded, array_flip(self::FIELDS));

                $validator = Validator::make($row, $rules);
                if ($validator->fails()) {
                    $this->count($manifest, 'rejected', 'invalid_field:'.array_key_first($validator->errors()->messages()), $line);

                    continue;
                }

                $placeId = (string) $row['place_id'];
                $pattern = $row['source'] === NerbyProvider::GOOGLE_PLACES->value ? self::GOOGLE_PLACE_ID : self::OSM_PLACE_ID;
                if (preg_match($pattern, $placeId) !== 1) {
                    $this->count($manifest, 'rejected', 'place_id_source_mismatch', $line);

                    continue;
                }

                // The ingest enforces this too; checking it here is what puts the line number in the
                // manifest and lets a dry run report it.
                if (isset($row['latitude'], $row['longitude'])
                    && ! $this->philippines->contains((float) $row['latitude'], (float) $row['longitude'])) {
                    $this->count($manifest, 'rejected', 'outside_philippines', $line);

                    continue;
                }

                $valid[$line] = $row;
            }
        } finally {
            fclose($handle);
        }

        $manifest['input']['lines'] = $line;
        $manifest['input']['sha256_read'] = hash_final($hash);
        $manifest['counts']['valid'] = count($valid);

        return $valid;
    }

    /**
     * The HTTP ingest's own per-business rules, reused rather than restated, tightened only where an
     * offline dataset needs more: place_id is REQUIRED (it is what makes a re-run skip, not
     * duplicate), source must be a known provider, and the two social columns this file may carry.
     *
     * @return array<string, mixed>
     */
    private function rules(): array
    {
        $rules = [];
        foreach ((new IngestDiscoveredLeadsRequest)->rules() as $key => $rule) {
            if (str_starts_with($key, 'businesses.*.')) {
                $field = substr($key, strlen('businesses.*.'));
                if (in_array($field, self::FIELDS, true)) {
                    $rules[$field] = $rule;
                }
            }
        }

        $rules['place_id'] = ['required', 'string', 'max:255'];
        $rules['source'] = ['required', 'string', Rule::in(self::sources())];
        $rules['facebook'] = ['nullable', 'string', 'max:255'];
        $rules['instagram'] = ['nullable', 'string', 'max:255'];

        return $rules;
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @param  list<string>  $sources
     * @param  array<string, mixed>  $manifest
     * @return array<int, array<string, mixed>>
     */
    private function applyFilters(array $rows, array $sources, string $aiFilter, array &$manifest): array
    {
        $kept = [];
        foreach ($rows as $line => $row) {
            if (! in_array($row['source'], $sources, true)) {
                $this->count($manifest, 'filtered', 'source_not_selected', $line);

                continue;
            }

            $ai = $row['discovery_ai_assisted'] ?? null;
            $flag = $ai === null ? 'null' : ((bool) $ai ? 'true' : 'false');
            if ($aiFilter !== self::AI_ANY && $flag !== $aiFilter) {
                $this->count($manifest, 'filtered', 'ai_flag_not_selected', $line);

                continue;
            }

            $kept[$line] = $row;
        }

        return $kept;
    }

    /**
     * Remove every row the TARGET org already holds — by exact place_id, soft-deleted leads included —
     * and repeats within the file (first line wins). Email is checked the same way because the ingest
     * also deduplicates on it. No business-name matching: two providers may name the same business
     * alike, and that alone is not evidence of a duplicate.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @param  array<string, mixed>  $manifest
     * @return array<int, array<string, mixed>>
     */
    private function dropExisting(array $rows, array &$manifest): array
    {
        $existing = $this->existingPlaceIds(array_map(fn (array $r): string => (string) $r['place_id'], $rows));
        $existingEmails = $this->existingEmails(array_filter(array_map(
            fn (array $r): ?string => isset($r['email']) && $r['email'] !== '' ? mb_strtolower((string) $r['email']) : null,
            $rows,
        )));

        $kept = [];
        $seenPlaceIds = [];
        $seenEmails = [];
        foreach ($rows as $line => $row) {
            $placeId = (string) $row['place_id'];
            $email = isset($row['email']) && $row['email'] !== '' ? mb_strtolower((string) $row['email']) : null;

            if (isset($existing[$placeId])) {
                $this->count($manifest, 'skipped', $existing[$placeId] ? 'existing_place_id_soft_deleted' : 'existing_place_id', $line);

                continue;
            }
            if (isset($seenPlaceIds[$placeId])) {
                $this->count($manifest, 'skipped', 'duplicate_place_id_in_file', $line);

                continue;
            }
            if ($email !== null && isset($existingEmails[$email])) {
                $this->count($manifest, 'skipped', 'existing_email', $line);

                continue;
            }
            if ($email !== null && isset($seenEmails[$email])) {
                $this->count($manifest, 'skipped', 'duplicate_email_in_file', $line);

                continue;
            }

            $seenPlaceIds[$placeId] = true;
            if ($email !== null) {
                $seenEmails[$email] = true;
            }
            $kept[$line] = $row;
        }

        return $kept;
    }

    /**
     * place_id => whether the only holder(s) are soft-deleted, for ids the current org already has.
     *
     * @param  array<int, string>  $placeIds
     * @return array<string, bool>
     */
    private function existingPlaceIds(array $placeIds): array
    {
        $map = [];
        foreach (array_chunk(array_values(array_unique($placeIds)), 1000) as $chunk) {
            foreach (Lead::withTrashed()->whereIn('place_id', $chunk)->get(['place_id', 'deleted_at']) as $lead) {
                $id = (string) $lead->place_id;
                $trashed = $lead->deleted_at !== null;
                $map[$id] = isset($map[$id]) ? ($map[$id] && $trashed) : $trashed;
            }
        }

        return $map;
    }

    /**
     * @param  array<int, string>  $emails  lowercased
     * @return array<string, true>
     */
    private function existingEmails(array $emails): array
    {
        $set = [];
        foreach (array_chunk(array_values(array_unique($emails)), 1000) as $chunk) {
            foreach (Lead::withTrashed()->whereNotNull('email')->whereIn(DB::raw('LOWER(email)'), $chunk)->pluck('email') as $email) {
                $set[mb_strtolower((string) $email)] = true;
            }
        }

        return $set;
    }

    /**
     * Hand the eligible rows to the unchanged ingest, a chunk at a time. Each chunk is re-checked
     * against the org immediately before its call, so a lead another process created since the
     * validation pass is skipped here rather than reaching the ingest's existing-lead branch.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @param  array<string, mixed>  $manifest
     */
    private function write(array $rows, int $actorId, array &$manifest): void
    {
        foreach (array_chunk($rows, self::CHUNK_SIZE, true) as $chunk) {
            $existing = $this->existingPlaceIds(array_map(fn (array $r): string => (string) $r['place_id'], $chunk));
            $batch = [];
            foreach ($chunk as $line => $row) {
                if (isset($existing[(string) $row['place_id']])) {
                    $this->count($manifest, 'skipped', 'existing_place_id_at_write', $line);
                    $manifest['counts']['eligible']--;

                    continue;
                }
                $batch[] = $row;
            }
            if ($batch === []) {
                continue;
            }

            $result = $this->ingest->ingestDiscovered($batch, $actorId, false);

            $manifest['ingest']['calls']++;
            $manifest['ingest']['valid'] += (int) $result['summary']['valid'];
            $manifest['ingest']['duplicates'] += (int) $result['summary']['duplicates'];
            $manifest['ingest']['rejected'] += (int) $result['summary']['rejected'];
            $manifest['ingest']['website_candidates'] += (int) ($result['summary']['websiteCandidates'] ?? 0);
            $manifest['counts']['imported'] += (int) $result['importedCount'];
        }
    }

    /** @param  array<string, mixed>  $manifest */
    private function count(array &$manifest, string $bucket, string $reason, int $line): void
    {
        $manifest['counts'][$bucket]++;
        $entry = $manifest['reasons'][$bucket][$reason] ?? ['count' => 0, 'lines' => []];
        $entry['count']++;
        if (count($entry['lines']) < self::MAX_EXAMPLE_LINES) {
            $entry['lines'][] = $line;
        }
        $manifest['reasons'][$bucket][$reason] = $entry;
    }

    /**
     * @param  array<string, mixed>  $manifest
     * @return array<string, mixed>
     */
    private function refuse(array $manifest, string $why): array
    {
        $manifest['status'] = 'refused';
        $manifest['error'] = $why;
        $manifest['counts']['imported'] = 0;

        return $this->finish($manifest);
    }

    /**
     * @param  array<string, mixed>  $manifest
     * @return array<string, mixed>
     */
    private function finish(array $manifest): array
    {
        $manifest['finished_at'] = now()->toIso8601String();

        return $manifest;
    }
}
