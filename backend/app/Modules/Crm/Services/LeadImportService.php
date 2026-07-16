<?php

namespace App\Modules\Crm\Services;

use App\Modules\Crm\Requests\StoreLeadRequest;
use App\Modules\Crm\Models\Lead;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use OpenSpout\Reader\CSV\Reader as CsvReader;
use OpenSpout\Reader\XLSX\Reader as XlsxReader;

/**
 * Parses a CSV/XLSX upload, auto-maps headers to Lead columns, validates each row
 * (reusing StoreLeadRequest rules), detects email duplicates at the application
 * level, and — on commit — creates the valid, non-duplicate leads.
 *
 * Synchronous by design (Sprint 6 MVP): no queues, no jobs, no batch persistence.
 * Activity logging is automatic via the existing LeadObserver on Lead::create.
 */
class LeadImportService
{
    /**
     * Header aliases → canonical Lead column. Header cells are normalized
     * (lowercased, trimmed, separators collapsed to single spaces) before lookup.
     *
     * @var array<string, list<string>>
     */
    private const COLUMN_ALIASES = [
        'business_name' => ['business name', 'business', 'company', 'company name', 'organization', 'organisation'],
        // Owner aliases per approved P1 (2026-07-07); stored apostrophe-free — normalizeHeader() strips apostrophes so "Owner's Name" == "Owners Name".
        'contact_name' => ['contact name', 'contact', 'name', 'full name', 'contact person', 'owners name', 'owner name', 'owner'],
        'email' => ['email', 'email address', 'contact email', 'e mail', 'mail'],
        'phone' => ['phone', 'phone number', 'telephone', 'tel', 'mobile', 'contact number'],
        'industry' => ['industry', 'business niche', 'niche', 'sector', 'client industry', 'vertical'],
        // Enum-target columns (approved business mapping P2–P5, 2026-07-07). Raw values are
        // run through the Shared Translation Engine (VALUE_TRANSLATIONS) before validation.
        // The "Website" column is repurposed as the Priority source (do NOT set website_status).
        'priority' => ['website', 'priority'],
        'email_outreach' => ['email outreach'],
        'cold_calling' => ['cold calling'],
        'status' => ['update', 'update status'],
        // Business address (Excel) — persisted to leads.address; the Tier-3 dedup discriminator.
        'address' => ['address', 'business address', 'street address', 'location'],
    ];

    /** Human labels for the mapping preview (crmField). */
    private const COLUMN_LABELS = [
        'business_name' => 'Business Name',
        'contact_name' => 'Contact Name',
        'email' => 'Email Address',
        'phone' => 'Phone Number',
        'industry' => 'Industry',
        'priority' => 'Priority',
        'email_outreach' => 'Email Outreach',
        'cold_calling' => 'Cold Calling',
        'status' => 'Update Status',
        'address' => 'Address',
    ];

    /** Columns whose raw text is translated to a CRM enum via the Shared Translation Engine. */
    private const TRANSLATED_FIELDS = ['priority', 'email_outreach', 'cold_calling', 'status'];

    /**
     * Shared Translation Engine value maps (approved by the Product Owner, 2026-07-07).
     * Keys are normalized (lowercased, trimmed, whitespace-collapsed) source values; values
     * are CRM enum members. An empty source value or an unrecognized value → the field is
     * omitted so the backend default applies; unrecognized values are also reported.
     *
     * @var array<string, array<string, string>>
     */
    private const VALUE_TRANSLATIONS = [
        // P2 — Website → Priority (weaker web presence = higher SEO opportunity). Live URLs → low via looksLikeUrl().
        'priority' => [
            'none found' => 'high',
            'none found (facebook only)' => 'high',
            'no website' => 'high',
            'facebook page' => 'high',
            'none found (ig only)' => 'high',
            'facebook only' => 'high',
            'none found (shopee storefront only)' => 'high',
            'no website found' => 'high',
            'google business site' => 'medium',
            'restore website' => 'medium',
            'fix bug and errors' => 'medium',
        ],
        // P3 — Email Outreach.
        'email_outreach' => [
            'completed' => 'sent',
            'in progress' => 'sent',
            'not started' => 'none',
        ],
        // P4 — Cold Calling.
        'cold_calling' => [
            'completed' => 'called',
            'in progress' => 'scheduled',
        ],
        // P5 — Update → Update Status: Option B. Do NOT interpret free-text. Empty map means every
        // non-empty value is unmapped → backend default ('new') + reported. No row errors.
        'status' => [],
    ];

    /**
     * @return array{
     *   dryRun: bool,
     *   fileName: string,
     *   summary: array{total: int, valid: int, restored: int, duplicates: int, errors: int},
     *   mapping: list<array{csvColumn: string, crmField: string}>,
     *   duplicates: list<array{row: int, email: string}>,
     *   restored: list<array{row: int, business: string}>,
     *   errors: list<array{row: int, messages: list<string>}>,
     *   unknownValues: list<array{field: string, value: string, count: int, rows: list<int>}>,
     *   importedCount: int
     * }
     *
     * @throws ValidationException when the file is empty or lacks the required columns
     */
    public function import(UploadedFile $file, int $userId, bool $dryRun, ?int $assignedTo = null): array
    {
        $rows = $this->readRows($file);

        if ($rows === []) {
            throw ValidationException::withMessages(['file' => 'The file is empty.']);
        }

        $header = array_shift($rows);
        $map = $this->mapHeader($header); // canonical column => column index

        if (! isset($map['business_name'], $map['contact_name'])) {
            throw ValidationException::withMessages([
                'file' => 'The file must contain "Business Name" and "Contact Name" columns.',
            ]);
        }

        $rules = (new StoreLeadRequest)->rules();

        // Import-only relaxation (PO-approved 2026-07-14; resolves the open Lead-Import decision
        // Addendum A.5 / A.4.4). Contact Name is OPTIONAL for imports: many legitimate lead sources
        // are company-only records (generic / sales / info@ / contact@ inboxes) with no identified
        // contact person yet. Only the `required` constraint is dropped — the string + max:255 checks
        // are kept, so validation is not weakened further. StoreLeadRequest itself is untouched, so
        // manual lead creation still requires a contact name. Email/duplicate/translation logic is
        // unchanged. A blank Owner's Name simply imports with an empty contact_name (column is
        // NOT NULL with no default → stored as '').
        $rules['contact_name'] = ['nullable', 'string', 'max:255'];

        /** @var list<array<string, string>> $newRows */
        $newRows = [];
        /** @var array<string, Lead> $toRestore  dedup key => soft-deleted lead to restore */
        $toRestore = [];
        /** @var list<array{row: int, email: string}> $duplicates */
        $duplicates = [];
        /** @var list<array{row: int, business: string}> $restored */
        $restored = [];
        /** @var list<array{row: int, messages: list<string>}> $errors */
        $errors = [];

        // Parse + translate every non-blank row first.
        $parsed = [];
        /** @var array<string, array{field: string, value: string, rows: list<int>}> $unknownAgg */
        $unknownAgg = [];
        foreach ($rows as $index => $cells) {
            $data = $this->extractRow($cells, $map);
            if ($data === null) {
                continue; // wholly blank row — skip silently
            }
            $rowNumber = $index + 2; // 1-based, past header
            $data = $this->translateValues($data, $rowNumber, $unknownAgg);
            $parsed[] = ['rowNumber' => $rowNumber, 'data' => $data];
        }

        // CRM-wide, ownership-independent deduplication index (Tier 1 email, Tier 2 business+phone,
        // Tier 3 business+address). Includes soft-deleted leads so a matching archived lead is
        // RESTORED rather than duplicated.
        [$activeKeys, $trashedByKey] = $this->existingDedupIndex($parsed);
        $seenKeys = [];

        foreach ($parsed as $item) {
            $rowNumber = $item['rowNumber'];
            $data = $item['data'];

            $validator = Validator::make($data, $rules);
            if ($validator->fails()) {
                $errors[] = ['row' => $rowNumber, 'messages' => array_values($validator->errors()->all())];

                continue;
            }

            $key = $this->dedupKey($data);
            $emailForReport = isset($data['email']) && $data['email'] !== '' ? $data['email'] : '';

            // Already active in the CRM (any BD), or an earlier row in this file → duplicate (skip).
            if (isset($activeKeys[$key]) || isset($seenKeys[$key])) {
                $duplicates[] = ['row' => $rowNumber, 'email' => $emailForReport];

                continue;
            }

            // Matches a soft-deleted lead → restore it instead of creating a new record.
            if (isset($trashedByKey[$key])) {
                $toRestore[$key] = $trashedByKey[$key];
                $restored[] = ['row' => $rowNumber, 'business' => $data['business_name'] ?? ''];
                $seenKeys[$key] = true;

                continue;
            }

            // New business → import (owned by the importing BD until reassigned).
            $seenKeys[$key] = true;
            $newRows[] = $data;
        }

        $importedCount = 0;
        if (! $dryRun) {
            DB::transaction(function () use ($newRows, $userId, $assignedTo, $toRestore, &$importedCount): void {
                if ($newRows !== []) {
                    $importedCount = $this->persist($newRows, $userId, $assignedTo);
                }
                foreach ($toRestore as $lead) {
                    $lead->restore(); // fires LeadObserver::restored → one immutable `lead.restored` Activity
                }
            });
        }

        return [
            'dryRun' => $dryRun,
            'fileName' => $file->getClientOriginalName(),
            'summary' => [
                'total' => count($parsed),
                // "valid" = rows that will land as an active lead this import (new + restored), so the
                // client's "Import N" affordance stays meaningful even for a restore-only file.
                'valid' => count($newRows) + count($toRestore),
                'restored' => count($toRestore),
                'duplicates' => count($duplicates),
                'errors' => count($errors),
            ],
            'mapping' => $this->mappingPreview($header, $map),
            'duplicates' => $duplicates,
            'restored' => $restored,
            'errors' => $errors,
            'unknownValues' => $this->formatUnknowns($unknownAgg),
            'importedCount' => $importedCount,
        ];
    }

    /**
     * Fit-data columns a discovery provider may land. This is the **entire** surface the enrichment
     * ingest writes — engagement / CRM-decision fields (priority, status, email_outreach, cold_calling,
     * score, assigned_to) are intentionally absent, so a data provider can never set them.
     *
     * @var list<string>
     */
    private const DISCOVERED_FIELDS = [
        'business_name', 'contact_name', 'email', 'phone', 'industry', 'website_status',
        'region', 'province', 'city', 'latitude', 'longitude', 'place_id', 'source', 'website',
    ];

    /**
     * Programmatic enrichment ingest — lands a batch of normalized, discovered businesses as leads.
     * Reuses the import path's persistence ({@see persist()}, which fires the LeadObserver "created"
     * Activity) with **assigned_to forced to null** (a data provider never assigns ownership).
     *
     * Idempotent re-ingestion: a business already present (matched by `place_id`, the provider's stable
     * natural key, or by email) is not re-created. On re-discovery, an existing business's **website**
     * URL is backfilled/refreshed when the provider now supplies one and it differs from what's stored
     * (fit-data only) — every other field is left untouched. Never computes a score, KPI, or engagement.
     *
     * @param  list<array<string, mixed>>  $businesses  validated rows from IngestDiscoveredLeadsRequest
     * @return array{
     *   dryRun: bool,
     *   summary: array{total: int, valid: int, duplicates: int, updated: int},
     *   importedCount: int
     * }
     */
    public function ingestDiscovered(array $businesses, int $userId, bool $dryRun): array
    {
        $total = count($businesses);

        /** @var list<array<string, mixed>> $rows */
        $rows = array_map(fn (array $b): array => $this->normalizeDiscovered($b), $businesses);

        $existingByPlaceId = $this->existingPlaceIdLeads($rows);
        $existingEmails = $this->existingDiscoveredEmailSet($rows);

        /** @var list<array<string, mixed>> $valid */
        $valid = [];
        /** @var array<int, string> $websiteBackfills  lead id => website */
        $websiteBackfills = [];
        $duplicates = 0;
        $seenPlaceIds = [];
        $seenEmails = [];

        foreach ($rows as $row) {
            $placeId = isset($row['place_id']) && $row['place_id'] !== '' ? (string) $row['place_id'] : null;
            $email = isset($row['email']) && $row['email'] !== '' ? mb_strtolower((string) $row['email']) : null;
            $website = isset($row['website']) && $row['website'] !== '' ? (string) $row['website'] : null;

            if ($placeId !== null && (isset($existingByPlaceId[$placeId]) || isset($seenPlaceIds[$placeId]))) {
                // Existing business — backfill the website URL if the provider now has one and it changed.
                $existing = $existingByPlaceId[$placeId] ?? null;
                if ($existing !== null && $website !== null && $existing->website !== $website) {
                    $websiteBackfills[(int) $existing->id] = $website;
                }
                $duplicates++;

                continue;
            }
            if ($email !== null && (isset($existingEmails[$email]) || isset($seenEmails[$email]))) {
                $duplicates++;

                continue;
            }

            if ($placeId !== null) {
                $seenPlaceIds[$placeId] = true;
            }
            if ($email !== null) {
                $seenEmails[$email] = true;
            }
            $valid[] = $row;
        }

        $importedCount = 0;
        if (! $dryRun) {
            DB::transaction(function () use ($valid, $userId, $websiteBackfills, &$importedCount): void {
                if ($valid !== []) {
                    // assigned_to = null: enrichment never assigns ownership (fit-data provider only).
                    $importedCount = $this->persist($valid, $userId, null);
                }
                foreach ($websiteBackfills as $leadId => $website) {
                    Lead::query()->whereKey($leadId)->update(['website' => $website]);
                }
            });
        }

        return [
            'dryRun' => $dryRun,
            'summary' => [
                'total' => $total,
                'valid' => count($valid),
                'duplicates' => $duplicates,
                'updated' => count($websiteBackfills),
            ],
            'importedCount' => $importedCount,
        ];
    }

    /**
     * Reduce a validated provider row to the fit-data allowlist. Trims strings, drops empty/null
     * values (so DB defaults apply, e.g. website_status), and defaults contact_name to the business
     * name when the provider exposed no contact person (documented normalization — not fabrication).
     *
     * @param  array<string, mixed>  $business
     * @return array<string, mixed>
     */
    private function normalizeDiscovered(array $business): array
    {
        $row = [];
        foreach (self::DISCOVERED_FIELDS as $field) {
            if (! array_key_exists($field, $business) || $business[$field] === null) {
                continue;
            }
            $value = $business[$field];
            if (is_string($value)) {
                $value = trim($value);
                if ($value === '') {
                    continue;
                }
            }
            $row[$field] = $value;
        }

        if (! isset($row['contact_name']) && isset($row['business_name'])) {
            $row['contact_name'] = $row['business_name'];
        }

        return $row;
    }

    /**
     * Existing leads for the incoming place_ids, keyed by place_id (id + website only) — used to
     * detect duplicates and to backfill the website URL on re-discovery.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, Lead>
     */
    private function existingPlaceIdLeads(array $rows): array
    {
        $placeIds = [];
        foreach ($rows as $row) {
            if (isset($row['place_id']) && $row['place_id'] !== '') {
                $placeIds[(string) $row['place_id']] = true;
            }
        }
        if ($placeIds === []) {
            return [];
        }

        $map = [];
        foreach (Lead::query()->whereIn('place_id', array_keys($placeIds))->get(['id', 'place_id', 'website']) as $lead) {
            $map[(string) $lead->place_id] = $lead;
        }

        return $map;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, true> lowercased emails already present on leads
     */
    private function existingDiscoveredEmailSet(array $rows): array
    {
        $emails = [];
        foreach ($rows as $row) {
            if (isset($row['email']) && $row['email'] !== '') {
                $emails[mb_strtolower((string) $row['email'])] = true;
            }
        }
        if ($emails === []) {
            return [];
        }

        $set = [];
        $matches = Lead::query()
            ->whereNotNull('email')
            ->whereIn(DB::raw('LOWER(email)'), array_keys($emails))
            ->pluck('email')
            ->all();
        foreach ($matches as $email) {
            $set[mb_strtolower((string) $email)] = true;
        }

        return $set;
    }

    /**
     * Shared Translation Engine (P2–P5): converts the enum-target columns' raw text to CRM
     * enum values. Empty → field omitted (backend default, not reported). Unrecognized non-empty
     * → field omitted (backend default) and recorded in $unknownAgg (never a row error).
     *
     * @param  array<string, string>  $data
     * @param  array<string, array{field: string, value: string, rows: list<int>}>  $unknownAgg
     * @return array<string, string>
     */
    private function translateValues(array $data, int $rowNumber, array &$unknownAgg): array
    {
        foreach (self::TRANSLATED_FIELDS as $field) {
            if (! array_key_exists($field, $data)) {
                continue; // column not present in this file
            }

            $raw = $data[$field];
            if ($raw === '') {
                unset($data[$field]); // empty → backend default (not reported)

                continue;
            }

            $enum = $this->translateValue($field, $raw);
            if ($enum !== null) {
                $data[$field] = $enum;

                continue;
            }

            // Unrecognized value → backend default + report; never a row error.
            unset($data[$field]);
            $key = $field.'|'.$this->normalizeValue($raw);
            if (! isset($unknownAgg[$key])) {
                $unknownAgg[$key] = ['field' => self::COLUMN_LABELS[$field], 'value' => $raw, 'rows' => []];
            }
            $unknownAgg[$key]['rows'][] = $rowNumber;
        }

        return $data;
    }

    private function translateValue(string $field, string $raw): ?string
    {
        $normalized = $this->normalizeValue($raw);
        $map = self::VALUE_TRANSLATIONS[$field] ?? [];

        if (isset($map[$normalized])) {
            return $map[$normalized];
        }

        // P2: a live website URL in the repurposed Website column → low priority.
        if ($field === 'priority' && $this->looksLikeUrl($normalized)) {
            return 'low';
        }

        return null; // unrecognized → caller applies backend default + reports
    }

    /**
     * @param  array<string, array{field: string, value: string, rows: list<int>}>  $unknownAgg
     * @return list<array{field: string, value: string, count: int, rows: list<int>}>
     */
    private function formatUnknowns(array $unknownAgg): array
    {
        $out = [];
        foreach ($unknownAgg as $entry) {
            $out[] = [
                'field' => $entry['field'],
                'value' => $entry['value'],
                'count' => count($entry['rows']),
                'rows' => $entry['rows'],
            ];
        }

        return $out;
    }

    private function normalizeValue(string $value): string
    {
        return (string) preg_replace('/\s+/', ' ', strtolower(trim($value)));
    }

    private function looksLikeUrl(string $normalized): bool
    {
        return str_starts_with($normalized, 'http://') || str_starts_with($normalized, 'https://');
    }

    /**
     * The deterministic, CRM-wide, ownership-independent dedup key for a parsed row (or an existing
     * lead, via {@see dedupKeyForLead}). Hierarchy (PO-approved 2026-07-14):
     *   Tier 1 — `e:<email>`                    when an email is present (strongest identity)
     *   Tier 2 — `bp:<business>|<phone>`        no email, phone present (branch discriminator)
     *   Tier 3 — `ba:<business>|<address>`      no email, no phone (address discriminates chains)
     * All parts are normalized so the same business always yields the same key across imports/BDs.
     *
     * @param  array<string, string>  $data
     */
    private function dedupKey(array $data): string
    {
        $email = isset($data['email']) && $data['email'] !== '' ? mb_strtolower(trim($data['email'])) : '';
        if ($email !== '') {
            return 'e:'.$email;
        }

        $business = $this->normBiz($data['business_name'] ?? '');
        $phone = isset($data['phone']) ? $this->normPhone($data['phone']) : '';
        if ($phone !== '') {
            return 'bp:'.$business.'|'.$phone;
        }

        $address = isset($data['address']) ? $this->normAddress($data['address']) : '';

        return 'ba:'.$business.'|'.$address;
    }

    /** Dedup key for an existing lead — same hierarchy/normalization as {@see dedupKey}. */
    private function dedupKeyForLead(Lead $lead): string
    {
        return $this->dedupKey([
            'email' => (string) ($lead->email ?? ''),
            'business_name' => (string) ($lead->business_name ?? ''),
            'phone' => (string) ($lead->phone ?? ''),
            'address' => (string) ($lead->address ?? ''),
        ]);
    }

    private function normBiz(string $value): string
    {
        return mb_strtolower(trim($value));
    }

    private function normPhone(string $value): string
    {
        $digits = (string) preg_replace('/\D+/', '', $value);
        // PH canonicalization: a +63XXXXXXXXXX form (12 digits, leading 63) == the local 0XXXXXXXXXX.
        if (strlen($digits) === 12 && str_starts_with($digits, '63')) {
            $digits = '0'.substr($digits, 2);
        }

        return $digits;
    }

    private function normAddress(string $value): string
    {
        return (string) preg_replace('/\s+/', ' ', mb_strtolower(trim($value)));
    }

    /**
     * Builds the CRM-wide dedup index (ownership-independent, soft-deletes included) for the file's
     * rows: one query over leads matching any of the file's emails or business names, keyed by
     * {@see dedupKeyForLead}. Returns [activeKeys, trashedByKey] — an active match ⇒ duplicate, a
     * soft-deleted match ⇒ restore.
     *
     * @param  list<array{rowNumber: int, data: array<string, string>}>  $parsed
     * @return array{0: array<string, true>, 1: array<string, Lead>}
     */
    private function existingDedupIndex(array $parsed): array
    {
        $emails = [];
        $bizNames = [];
        foreach ($parsed as $item) {
            $data = $item['data'];
            if (isset($data['email']) && $data['email'] !== '') {
                $emails[mb_strtolower(trim($data['email']))] = true;
            }
            if (isset($data['business_name']) && $data['business_name'] !== '') {
                $bizNames[$this->normBiz($data['business_name'])] = true;
            }
        }

        /** @var array<string, true> $activeKeys */
        $activeKeys = [];
        /** @var array<string, Lead> $trashedByKey */
        $trashedByKey = [];
        if ($emails === [] && $bizNames === []) {
            return [$activeKeys, $trashedByKey];
        }

        $leads = Lead::withTrashed()
            ->where(function (Builder $q) use ($emails, $bizNames): void {
                if ($emails !== []) {
                    $q->orWhereIn(DB::raw('LOWER(TRIM(email))'), array_keys($emails));
                }
                if ($bizNames !== []) {
                    $q->orWhereIn(DB::raw('LOWER(TRIM(business_name))'), array_keys($bizNames));
                }
            })
            ->get(['id', 'business_name', 'email', 'phone', 'address', 'deleted_at']);

        foreach ($leads as $lead) {
            $key = $this->dedupKeyForLead($lead);
            if ($lead->deleted_at === null) {
                $activeKeys[$key] = true;
            } elseif (! isset($trashedByKey[$key])) {
                $trashedByKey[$key] = $lead;
            }
        }

        return [$activeKeys, $trashedByKey];
    }

    /**
     * @param  list<array<string, string>>  $validRows
     */
    private function persist(array $validRows, int $userId, ?int $assignedTo = null): int
    {
        return DB::transaction(function () use ($validRows, $userId, $assignedTo): int {
            $count = 0;
            foreach ($validRows as $data) {
                // LeadObserver records the "created" Activity automatically.
                Lead::create([...$data, 'created_by' => $userId, 'assigned_to' => $assignedTo]);
                $count++;
            }

            return $count;
        });
    }

    /**
     * Reads the first sheet into rows of scalar cell values.
     *
     * @return list<list<string>>
     */
    private function readRows(UploadedFile $file): array
    {
        $extension = strtolower($file->getClientOriginalExtension());
        $reader = $extension === 'xlsx' ? new XlsxReader : new CsvReader;

        $reader->open((string) $file->getRealPath());

        $rows = [];
        foreach ($reader->getSheetIterator() as $sheet) {
            foreach ($sheet->getRowIterator() as $row) {
                $cells = [];
                foreach ($row->toArray() as $value) {
                    $cells[] = $this->stringify($value);
                }
                $rows[] = $cells;
            }
            break; // MVP: first sheet only
        }
        $reader->close();

        return $rows;
    }

    /**
     * @param  list<string>  $header
     * @return array<string, int> canonical column => header index
     */
    private function mapHeader(array $header): array
    {
        $map = [];
        foreach ($header as $index => $cell) {
            $normalized = $this->normalizeHeader($cell);
            if ($normalized === '') {
                continue;
            }
            foreach (self::COLUMN_ALIASES as $column => $aliases) {
                if (isset($map[$column])) {
                    continue; // first matching header wins
                }
                if (in_array($normalized, $aliases, true)) {
                    $map[$column] = $index;
                }
            }
        }

        return $map;
    }

    /**
     * @param  list<string>  $cells
     * @param  array<string, int>  $map
     * @return array<string, string>|null null when the row is wholly blank
     */
    private function extractRow(array $cells, array $map): ?array
    {
        $data = [];
        $hasValue = false;
        foreach ($map as $column => $index) {
            $value = trim($cells[$index] ?? '');
            if ($value !== '') {
                $hasValue = true;
            }
            $data[$column] = $value;
        }

        return $hasValue ? $data : null;
    }

    /**
     * @param  list<string>  $header
     * @param  array<string, int>  $map
     * @return list<array{csvColumn: string, crmField: string}>
     */
    private function mappingPreview(array $header, array $map): array
    {
        $preview = [];
        foreach ($map as $column => $index) {
            $preview[] = [
                'csvColumn' => trim($header[$index]),
                'crmField' => self::COLUMN_LABELS[$column],
            ];
        }

        return $preview;
    }

    private function normalizeHeader(string $value): string
    {
        $value = strtolower(trim($value));
        // Strip apostrophes (straight + curly) so "Owner's Name" and "Owners Name" resolve identically.
        $value = str_replace(["'", "\u{2019}", "\u{2018}"], '', $value);

        return (string) preg_replace('/[\s_]+/', ' ', $value);
    }

    private function stringify(mixed $value): string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d');
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        if (is_scalar($value)) {
            return (string) $value;
        }

        return '';
    }
}
