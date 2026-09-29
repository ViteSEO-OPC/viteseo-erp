<?php

namespace App\Modules\Crm\Services;

use App\Models\User;
use App\Modules\Crm\Models\Lead;
use App\Modules\Crm\Requests\StoreLeadRequest;
use App\Modules\Crm\Services\Discovery\DiscoveryLaneRecorder;
use App\Modules\Crm\Services\Discovery\Geo\PhilippinesBoundary;
use App\Modules\Crm\Models\LeadWebsiteCandidate;
use App\Modules\Crm\Services\Website\WebsiteEvidenceService;
use App\Modules\Crm\Services\Website\WebsiteUrlClassifier;
use App\Modules\Crm\Services\Import\LeadImportInProgressException;
use App\Modules\Crm\Services\Import\XlsConverter;
use App\Support\CurrentOrg;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
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
 *
 * ── Accepted header contract (canonical table: `Lead-Import-Contract.md` in the Obsidian KB) ──
 *
 * REQUIRED — exactly one column:
 *   Business Name        business name | business | company | company name | organization
 *
 * OPTIONAL — every other column, in any order; unknown headers are reported, never fatal:
 *   Contact Name         contact name | contact | name | full name | contact person |
 *                        owner's name | owner name | owner | representative
 *   Email / Phone / Industry / Address / Acquisition Channel / Website Domain
 *   Priority             ⚠ a bare "Website" header maps to PRIORITY, not the domain
 *   Email Outreach / Cold Calling / Update Status  (translated to enums)
 *   Facebook / Instagram / TikTok / LinkedIn / Shopee / Lazada / TikTok Shop / Other Marketplace
 *   Lead Note            comment | comments | note | notes | remarks → a crm_notes row, NOT a
 *                        column on crm_leads; new leads only, truncated at NOTE_MAX_LENGTH
 *
 * A missing contact column defaults `contact_name` to '' before validation and persistence — see
 * the note in import() for why omitting it is a 500 rather than a blank.
 */
class LeadImportService
{
    /**
     * Hard ceiling for an imported Comment, mirroring StoreLeadRequest's `max:5000` on `note`.
     * A longer comment is TRUNCATED, never rejected: the column was silently ignored before this
     * mapping existed, so failing an otherwise-valid row because of it would be a regression in
     * the name of a new feature (PO decision, 2026-08-14).
     */
    private const NOTE_MAX_LENGTH = 5000;

    /**
     * Lock key prefix for a lead-import COMMIT, per organization (CEO audit crm-06). A commit reads the
     * dedup index, classifies the rows and only then writes, so two commits in one organization must not
     * overlap: the second would read the index before the first had written and create every lead again.
     * Leads are organization-scoped, so the organization is both the dedup domain and the lock granularity.
     */
    public const COMMIT_LOCK_PREFIX = 'crm:lead-import:commit:org:';

    /** A safety net only: a normal commit releases the lock the moment its transaction ends. */
    private const COMMIT_LOCK_SECONDS = 600;

    /**
     * Most data rows a single import may carry (CEO audit crm-06). A commit writes about seven statements
     * per row inside one request and one transaction, so an uncapped 10 MB sheet (~60,000 rows) could hold
     * the lead tables for minutes and outlive every client and proxy timeout. Enforced while the file is
     * read — before preview, deduplication, the commit lock or any write — so an over-cap file costs one
     * bounded read and writes nothing. The header row and wholly blank rows do not count.
     */
    public const MAX_IMPORT_ROWS = 5000;

    public function __construct(
        private readonly XlsConverter $xls = new XlsConverter,
        private readonly ?LeadNoteCreator $notes = null,
    ) {}

    /** Resolved lazily so the existing `new LeadImportService` call sites keep working. */
    private function noteCreator(): LeadNoteCreator
    {
        return $this->notes ?? app(LeadNoteCreator::class);
    }

    /**
     * Header aliases → canonical Lead column. Header cells are normalized
     * (lowercased, trimmed, separators collapsed to single spaces) before lookup.
     *
     * @var array<string, list<string>>
     */
    private const COLUMN_ALIASES = [
        'business_name' => ['business name', 'business', 'company', 'company name', 'organization', 'organisation'],
        // Owner aliases per approved P1 (2026-07-07); stored apostrophe-free — normalizeHeader() strips apostrophes so "Owner's Name" == "Owners Name".
        'contact_name' => ['contact name', 'contact', 'name', 'full name', 'contact person', 'owners name', 'owner name', 'owner', 'representative'],
        'email' => ['email', 'email address', 'contact email', 'e mail', 'mail'],
        'phone' => ['phone', 'phone number', 'telephone', 'tel', 'mobile', 'mobile number', 'contact number'],
        'industry' => ['industry', 'business niche', 'niche', 'sector', 'client industry', 'vertical'],
        // Lead Acquisition Channel — the business "Source" (Referral, Facebook, Website, Cold Outreach,
        // …). Maps to `acquisition_channel`, NEVER to `crm_leads.source` (discovery provenance). Plain
        // pass-through string (not a translated enum) so an enterprise sheet never loses a channel.
        'acquisition_channel' => ['source', 'lead source', 'acquisition channel', 'marketing source', 'channel'],
        // Enum-target columns (approved business mapping P2–P5, 2026-07-07). Raw values are
        // run through the Shared Translation Engine (VALUE_TRANSLATIONS) before validation.
        // The "Website" column is repurposed as the Priority source (do NOT set website_status).
        'priority' => ['website', 'priority', 'priority level'],
        'email_outreach' => ['email outreach'],
        'cold_calling' => ['cold calling'],
        'status' => ['update', 'update status'],
        // Business address (Excel) — persisted to leads.address; the Tier-3 dedup discriminator.
        'address' => ['address', 'business address', 'street address', 'location'],
        // Website Domain → leads.website. NOT the bare "website" header (that stays the repurposed
        // Priority source above, per P2); a domain column must be named explicitly.
        'website' => ['website domain', 'domain', 'website url', 'web address', 'site url'],
        // Digital Presence (social) — plain pass-through strings. A sheet WITHOUT these columns
        // imports unchanged (values stored NULL); WITH them, values are captured. No translation.
        'facebook' => ['facebook', 'facebook page', 'fb', 'facebook url'],
        'instagram' => ['instagram', 'ig', 'instagram url', 'instagram handle'],
        'tiktok' => ['tiktok', 'tik tok', 'tiktok url', 'tiktok handle'],
        'linkedin' => ['linkedin', 'linked in', 'linkedin url'],
        // Marketplace Presence (e-commerce) — plain pass-through strings; absent → NULL.
        'shopee' => ['shopee', 'shopee store', 'shopee url'],
        'lazada' => ['lazada', 'lazada store', 'lazada url'],
        'tiktok_shop' => ['tiktok shop', 'tik tok shop', 'tiktok store'],
        'other_marketplace' => ['other marketplace', 'other e commerce', 'other ecommerce', 'other e commerce store'],
        // Free-text remarks → the lead's FIRST internal note (crm_notes), never a column on
        // crm_leads. Routed through LeadNoteCreator in persist(), i.e. the same path the Create Lead
        // wizard and the Lead Details composer use, so the note gets the same author association and
        // the same `note.created` Activity. Sheets without this column are unaffected.
        'note' => ['comment', 'comments', 'note', 'notes', 'remarks'],
    ];

    /** Human labels for the mapping preview (crmField). */
    private const COLUMN_LABELS = [
        'business_name' => 'Business Name',
        'contact_name' => 'Contact Name',
        'email' => 'Email Address',
        'phone' => 'Phone Number',
        'industry' => 'Industry',
        'acquisition_channel' => 'Acquisition Channel',
        'priority' => 'Priority',
        'email_outreach' => 'Email Outreach',
        'cold_calling' => 'Cold Calling',
        'status' => 'Update Status',
        'address' => 'Address',
        'website' => 'Website Domain',
        'facebook' => 'Facebook',
        'instagram' => 'Instagram',
        'tiktok' => 'TikTok',
        'linkedin' => 'LinkedIn',
        'shopee' => 'Shopee',
        'lazada' => 'Lazada',
        'tiktok_shop' => 'TikTok Shop',
        'other_marketplace' => 'Other Marketplace',
        // Required: mappingPreview() reads this map directly, so an alias without a label here
        // would fault the preview rather than merely mislabel it.
        'note' => 'Lead Note',
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
     *   unknownColumns: list<string>,
     *   duplicates: list<array{row: int, email: string}>,
     *   restored: list<array{row: int, business: string}>,
     *   errors: list<array{row: int, messages: list<string>}>,
     *   unknownValues: list<array{field: string, value: string, count: int, rows: list<int>}>,
     *   importedCount: int
     * }
     *
     * @throws ValidationException when the file is empty or lacks the required columns
     */
    public function import(
        UploadedFile $file,
        int $userId,
        bool $dryRun,
        ?int $assignedTo = null,
        ?string $preConvertedPath = null,
    ): array {
        // A large `.xls` was already normalized to `.xlsx` by the queued job; reuse that result
        // rather than converting a second time. Every other format reads straight from the upload.
        $rows = $preConvertedPath !== null
            ? $this->readRows($preConvertedPath, 'xlsx')
            : $this->readRows(
                (string) $file->getRealPath(),
                strtolower($file->getClientOriginalExtension()),
            );

        if ($rows === []) {
            throw ValidationException::withMessages(['file' => 'The file is empty.']);
        }

        $header = array_shift($rows);
        $map = $this->mapHeader($header); // canonical column => column index

        // Business Name is the ONLY required header (PO decision, 2026-08-14). Contact information —
        // including "Contact Name" / "Owner's Name" — is optional, because the overwhelming majority
        // of prospecting sheets are company-only records: in the workbook that prompted this change,
        // just 60 of 455 rows carried an owner name, yet the whole file was rejected for a column it
        // barely used. Every other supported column stays optional exactly as before.
        if (! isset($map['business_name'])) {
            throw ValidationException::withMessages([
                'file' => 'The file must contain a "Business Name" column.',
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

        // Import-only relaxation of `note` for the SAME reason: an imported Comment is truncated to
        // NOTE_MAX_LENGTH in persist(), so keeping StoreLeadRequest's `max:5000` here would reject
        // the row before the truncation could ever run — the opposite of the approved behaviour.
        // The `string` check is kept; only the length ceiling moves to persist().
        $rules['note'] = ['nullable', 'string'];

        // Parse + translate every non-blank row first.
        $parsed = [];
        /** @var array<string, array{field: string, value: string, rows: list<int>}> $unknownAgg */
        $unknownAgg = [];
        foreach ($rows as $index => $cells) {
            $data = $this->extractRow($cells, $map);
            if ($data === null) {
                continue; // wholly blank row — skip silently
            }
            // extractRow only emits keys present in the header map, so a sheet WITHOUT any contact
            // column yields no `contact_name` at all. `crm_leads.contact_name` is NOT NULL with no
            // default and MySQL runs STRICT_TRANS_TABLES, so Lead::create() would fail with
            // "Field 'contact_name' doesn't have a default value" — a 500 on commit, after a preview
            // that looked perfectly clean. Defaulting here keeps the empty-contact behaviour
            // identical to a present-but-blank column.
            $data['contact_name'] ??= '';
            $rowNumber = $index + 2; // 1-based, past header
            $data = $this->translateValues($data, $rowNumber, $unknownAgg);
            $parsed[] = ['rowNumber' => $rowNumber, 'data' => $data];
        }

        // Dedup read → classification → commit is one unit. For a commit it runs under the organization's
        // import lock, so no other commit can read the dedup index before this one has written. A preview
        // writes nothing and never takes the lock.
        [$newRows, $toRestore, $duplicates, $restored, $errors, $importedCount] = $dryRun
            ? $this->classifyAndCommit($parsed, $rules, $userId, $assignedTo, commit: false)
            : $this->underCommitLock(fn (): array => $this->classifyAndCommit($parsed, $rules, $userId, $assignedTo, commit: true));

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
            // Enterprise Smart Header Recognition: named header cells that matched no CRM field, so
            // the importer can see exactly which spreadsheet columns were ignored (never a failure).
            'unknownColumns' => $this->unknownColumns($header, $map),
            'duplicates' => $duplicates,
            'restored' => $restored,
            'errors' => $errors,
            'unknownValues' => $this->formatUnknowns($unknownAgg),
            'importedCount' => $importedCount,
        ];
    }

    /**
     * Classify the parsed rows against the CRM-wide dedup index and, for a commit, write them. The import
     * rules are unchanged; they are only gathered here so a commit can run them under {@see underCommitLock()}.
     *
     * @param  list<array{rowNumber: int, data: array<string, string>}>  $parsed
     * @param  array<string, mixed>  $rules
     * @return array{0: list<array<string, string>>, 1: array<string, Lead>, 2: list<array{row: int, email: string}>, 3: list<array{row: int, business: string}>, 4: list<array{row: int, messages: list<string>}>, 5: int}
     */
    private function classifyAndCommit(array $parsed, array $rules, int $userId, ?int $assignedTo, bool $commit): array
    {
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
        if ($commit) {
            DB::transaction(function () use ($newRows, $userId, $assignedTo, $toRestore, &$importedCount): void {
                if ($newRows !== []) {
                    $importedCount = $this->persist($newRows, $userId, $assignedTo);
                }
                foreach ($toRestore as $lead) {
                    // Deliberately no note here, even when the row carries a Comment (PO decision,
                    // 2026-08-14): a restore re-activates an existing lead that already has its own
                    // note history, and restore has never written anything but the restore itself.
                    // Comment → Note applies to genuinely NEW leads only.
                    $lead->restore(); // fires LeadObserver::restored → one immutable `lead.restored` Activity
                }
            });
        }

        return [$newRows, $toRestore, $duplicates, $restored, $errors, $importedCount];
    }

    /**
     * Run a commit under the organization's lead-import lock (CEO audit crm-06).
     *
     * Taken BEFORE the dedup read and before any transaction opens — the lock row must be visible to other
     * requests immediately — and released only after the transaction has committed or rolled back. It never
     * waits: a commit that finds the lock taken is refused, and the same commit succeeds once the other
     * import has finished.
     *
     * @template T
     *
     * @param  callable(): T  $commit
     * @return T
     *
     * @throws LeadImportInProgressException
     */
    private function underCommitLock(callable $commit): mixed
    {
        $lock = Cache::lock(self::COMMIT_LOCK_PREFIX.(CurrentOrg::id() ?? 'none'), self::COMMIT_LOCK_SECONDS);

        if (! $lock->get()) {
            throw new LeadImportInProgressException;
        }

        try {
            return $commit();
        } finally {
            $lock->release();
        }
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
        // Per-lead AI provenance. Carried beside `source`, never folded into it.
        'discovery_ai_assisted',
        // Where a discovered SOCIAL url is filed. The provider does not send these; the normalizer
        // below routes them here after classifying the single URL the provider does send, so a
        // Facebook page is kept as a Facebook page instead of being recorded as a website.
        'facebook', 'instagram',
    ];

    /**
     * The storage ceiling for `crm_leads.website`, mirroring both the column and the ingest
     * validator's own `max:2048`. Kept as a named constant because it is a DATA CONTRACT shared by
     * three places (column, request rule, and the normalizer's defensive drop below) — when they
     * disagree, a provider URL passes validation and then dies at the INSERT.
     */
    private const WEBSITE_MAX_LENGTH = 2048;

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
     * @param  array<int, mixed>  $provenanceOnly  AI-lane findings the engine's per-search limit dropped
     *                                             (NERBY run callers only). They annotate existing leads'
     *                                             discovery lanes and never create or change a lead.
     * @return array{
     *   dryRun: bool,
     *   summary: array{total: int, valid: int, duplicates: int, updated: int},
     *   importedCount: int
     * }
     */
    public function ingestDiscovered(array $businesses, int $userId, bool $dryRun, ?\Closure $identityCheck = null, ?int $discoveryRunId = null, array $provenanceOnly = []): array
    {
        $total = count($businesses);

        // IDENTITY BEFORE ANYTHING ELSE. A row whose provider identity cannot be vouched for — a
        // Google place the CRM never returned to this caller, fields that differ from what Google
        // said, an identity shape no provider produces — is refused here, counted, and never
        // normalised, deduplicated or stored. See GooglePlacesAttestation.
        $unverified = 0;
        if ($identityCheck !== null) {
            $businesses = array_values(array_filter($businesses, function (array $b) use ($identityCheck, &$unverified): bool {
                if ($identityCheck($b) === true) {
                    return true;
                }
                $unverified++;

                return false;
            }));
        }

        /** @var list<array<string, mixed>> $rows */
        $rows = array_map(fn (array $b): array => $this->normalizeDiscovered($b), $businesses);

        // WHICH PLACES THE AI LANE FOUND, read before anything else can drop it: normalisation keeps
        // only the fit-data allowlist, so the claim is taken from the raw row, which `$rows` still
        // lines up with here. A row is an AI finding when the AI lane is its source, or when the AI
        // lane found it in the same search as the baseline. A Google row never counts.
        $aiPlaceIds = [];
        foreach ($businesses as $i => $b) {
            $source = (string) ($b['source'] ?? '');
            $also = is_array($b['discovery_also_found_by'] ?? null) ? $b['discovery_also_found_by'] : [];
            $placeId = (string) ($rows[$i]['place_id'] ?? '');
            if ($placeId !== '' && $source !== 'google_places'
                && ($source === DiscoveryLaneRecorder::LANE_AI || in_array(DiscoveryLaneRecorder::LANE_AI, $also, true))) {
                $aiPlaceIds[$placeId] = true;
            }
        }

        // THE NATIONAL BOUNDARY, ENFORCED AT THE ONLY DOOR. Every discovered business enters the CRM
        // through this method, whichever provider found it and whichever process sent it, so this is
        // where "Philippines only" is decided — not in the planner that drew the search box, and not
        // in the engine that made the call. A bounded request is an instruction to a provider; this
        // is a fact about the business, checked against the vendored national polygon with no
        // tolerance. A row outside the country is REJECTED and counted, never stored and never
        // silently dropped. Rows with no coordinates are untouched by this rule: absence of a
        // position is not evidence of a foreign one, and pre-existing providers legitimately send
        // businesses without one.
        $rejected = 0;
        $rows = array_values(array_filter($rows, function (array $row) use (&$rejected): bool {
            if (! isset($row['latitude'], $row['longitude'])) {
                return true;
            }
            if ($this->philippines()->contains((float) $row['latitude'], (float) $row['longitude'])) {
                return true;
            }
            $rejected++;

            return false;
        }));

        // PROVENANCE-ONLY AI FINDINGS, checked entry by entry: never part of the result, never counted
        // in total / valid / duplicates / rejected, never a lead. Only an OSM identity with a position
        // inside the national boundary survives — the same boundary every discovered business meets.
        $provenance = $this->provenanceOnlyPlaceIds($provenanceOnly);

        $existingByPlaceId = $this->existingPlaceIdLeads($rows);
        $existingEmails = $this->existingDiscoveredEmailSet($rows);

        /** @var list<array<string, mixed>> $valid */
        $valid = [];
        /** @var array<int, array{website: string, source: string}> $websiteCandidates  lead id => proposal */
        $websiteCandidates = [];
        $duplicates = 0;
        $seenPlaceIds = [];
        $seenEmails = [];

        foreach ($rows as $row) {
            $placeId = isset($row['place_id']) && $row['place_id'] !== '' ? (string) $row['place_id'] : null;
            $email = isset($row['email']) && $row['email'] !== '' ? mb_strtolower((string) $row['email']) : null;
            $website = isset($row['website']) && $row['website'] !== '' ? (string) $row['website'] : null;

            if ($placeId !== null && (isset($existingByPlaceId[$placeId]) || isset($seenPlaceIds[$placeId]))) {
                // Existing business with a different provider URL. NEVER written to `website`
                // directly: that bypassed Website Intelligence and could replace an audited URL or a
                // confirmed absence. It is proposed as a candidate instead, and only the identity
                // verifier can promote it (WebsiteEvidenceService).
                $existing = $existingByPlaceId[$placeId] ?? null;
                if ($existing !== null && $website !== null && $existing->website !== $website) {
                    $websiteCandidates[(int) $existing->id] = [
                        'website' => $website,
                        'source' => (string) ($row['source'] ?? 'discovery'),
                    ];
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
            DB::transaction(function () use ($valid, $userId, &$importedCount): void {
                if ($valid !== []) {
                    // assigned_to = null: enrichment never assigns ownership (fit-data provider only).
                    $importedCount = $this->persist($valid, $userId, null);
                }
            });

            // ADDITIONAL PROVENANCE, recorded beside the leads and never on them (change set D). Only
            // for rows that survived identity and the national boundary; after the insert above, so a
            // lead this batch created is found too. Never on a dry run.
            $kept = [];
            foreach ($rows as $row) {
                if (isset($row['place_id']) && isset($aiPlaceIds[(string) $row['place_id']])) {
                    $kept[] = (string) $row['place_id'];
                }
            }
            // ONE recording for the batch: the rows' own AI findings plus the provenance-only ones, so
            // a business that arrives both ways is one sighting. After the insert above, so an AI
            // finding that another row of this batch just created is matched too. The recorder only
            // ever matches an existing, non-Google lead by place_id — it cannot create one.
            $recordedAi = app(DiscoveryLaneRecorder::class)->recordAi(
                array_merge($kept, $provenance['placeIds']),
                $discoveryRunId,
            );
            $provenance['recorded'] = count(array_intersect($provenance['placeIds'], $recordedAi));

            $evidence = app(WebsiteEvidenceService::class);
            foreach ($websiteCandidates as $leadId => $proposal) {
                $lead = Lead::query()->find($leadId);
                if ($lead !== null) {
                    $evidence->recordCandidates($lead, [[
                        'url' => $proposal['website'],
                        'source' => LeadWebsiteCandidate::SOURCE_SEARCH,
                        'source_detail' => mb_substr('discovery:'.$proposal['source'], 0, 255),
                    ]], []);
                }
            }
        }

        return [
            'dryRun' => $dryRun,
            'summary' => [
                'total' => $total,
                'valid' => count($valid),
                'duplicates' => $duplicates,
                // Existing leads are no longer written by discovery; kept for response-shape stability.
                'updated' => 0,
                // Different provider URLs for existing leads, proposed to Website Intelligence.
                'websiteCandidates' => count($websiteCandidates),
                // Rows refused because their provider identity could not be vouched for.
                'unverified' => $unverified,
                // Businesses refused by the national boundary. Surfaced so a run can report honestly
                // that a provider returned results the CRM would not accept, rather than showing an
                // unexplained shortfall between "found" and "added".
                'rejected' => $rejected + $unverified,
                // Present only when the engine sent provenance-only findings, so every other response
                // keeps its exact shape. `recorded` is how many matched an existing eligible lead.
                ...($provenance['received'] > 0 ? ['provenanceOnly' => [
                    'received' => $provenance['received'],
                    'invalid' => $provenance['invalid'],
                    'outsidePh' => $provenance['outsidePh'],
                    'recorded' => $provenance['recorded'],
                ]] : []),
            ],
            'importedCount' => $importedCount,
        ];
    }

    /** Most provenance-only findings accepted from one request; the rest are counted as invalid. */
    private const PROVENANCE_ONLY_MAX = 500;

    /** The OpenStreetMap element key — the only identity a provenance-only finding may carry. */
    private const OSM_PLACE_ID = '#^(node|way|relation)/[1-9][0-9]{0,18}$#';

    /**
     * Reduce provenance-only findings to the place ids that may be recorded.
     *
     * An entry survives only as `{place_id: <OSM element>, lane: "openai", latitude, longitude}` with
     * a position inside the national polygon. Anything else — a Google place id, another lane, a
     * missing or impossible coordinate, a non-object — is dropped and counted, never fatal: one bad
     * entry must not cost the batch it travelled with.
     *
     * @param  array<int, mixed>  $entries
     * @return array{placeIds: list<string>, received: int, invalid: int, outsidePh: int, recorded: int}
     */
    private function provenanceOnlyPlaceIds(array $entries): array
    {
        $result = ['placeIds' => [], 'received' => count($entries), 'invalid' => 0, 'outsidePh' => 0, 'recorded' => 0];

        foreach (array_values($entries) as $i => $entry) {
            $placeId = is_array($entry) ? ($entry['place_id'] ?? null) : null;
            $lat = is_array($entry) ? ($entry['latitude'] ?? null) : null;
            $lng = is_array($entry) ? ($entry['longitude'] ?? null) : null;

            if ($i >= self::PROVENANCE_ONLY_MAX
                || ! is_string($placeId) || preg_match(self::OSM_PLACE_ID, $placeId) !== 1
                || ($entry['lane'] ?? null) !== DiscoveryLaneRecorder::LANE_AI
                || ! is_numeric($lat) || ! is_numeric($lng)
                || abs((float) $lat) > 90 || abs((float) $lng) > 180) {
                $result['invalid']++;

                continue;
            }
            if (! $this->philippines()->contains((float) $lat, (float) $lng)) {
                $result['outsidePh']++;

                continue;
            }
            $result['placeIds'][$placeId] = $placeId;
        }
        $result['placeIds'] = array_values($result['placeIds']);

        return $result;
    }

    /**
     * Reduce a validated provider row to the fit-data allowlist. Trims strings, drops empty/null
     * values (so DB defaults apply, e.g. website_status), and defaults contact_name to the business
     * name when the provider exposed no contact person (documented normalization — not fabrication).
     *
     * `website_status` is the one field the provider cannot decide. Discovery observes whether a
     * directory record happened to carry a URL — never whether a website exists, works, or belongs to
     * the business — so any status it reports is overwritten with `website-unknown` and left for the
     * audit (WebsiteIdentityVerifier) to resolve. The field stays
     * ACCEPTED rather than rejected so an older deployed scraper keeps ingesting normally: the CRM and
     * the scraper release independently, and a validation error here would silently stop discovery.
     *
     * @param  array<string, mixed>  $business
     * @return array<string, mixed>
     */
    /**
     * The national boundary, resolved lazily.
     *
     * Deliberately NOT a constructor dependency: this service is also constructed directly in places
     * that have nothing to do with discovery (the spreadsheet import path), and the boundary parses a
     * 160KB polygon on first use. Resolving it here means the CSV importer never pays for it, while
     * the discovery ingest gets the container's shared instance.
     */
    private function philippines(): PhilippinesBoundary
    {
        return app(PhilippinesBoundary::class);
    }

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

        // THE BUSINESS SURVIVES ITS OWN BAD FIELD. A provider URL longer than the column can hold
        // used to reach the INSERT and raise SQLSTATE[22001], which the endpoint returned as a 500
        // and the scraper treated as a dead target — so one oversized URL discarded every other
        // valid business discovered alongside it. The URL is DROPPED rather than cut: a truncated
        // link is a broken link, and inventing a shortened one would be fabricating provider data.
        // The lead itself is kept, and Website Intelligence will search for its site later exactly
        // as it does for any discovered business that arrived without one.
        if (isset($row['website']) && mb_strlen((string) $row['website']) > self::WEBSITE_MAX_LENGTH) {
            unset($row['website']);
        }

        // A URL IS NOT AUTOMATICALLY A WEBSITE. Google Places returns one `websiteUri` per place and
        // it is whatever the owner typed: measured on this pool, a third of them were Facebook or
        // Instagram profiles. Storing those in `website` makes the board show a social profile under
        // WEBSITE, and — far worse — offers the audit a page that will pass identity verification on
        // its title and phone, because the page really is about the business. The lead then reads as
        // "has a website" when its only web presence is somebody else's platform, which is the exact
        // inverse of the prospect signal it was discovered for.
        //
        // Classified by HOST, which needs no fetch and so can happen here at the door. Social URLs
        // are MOVED, never dropped — the Lead has had `facebook` and `instagram` columns all along.
        // Directory listings and link-in-bio hubs have no column and are simply not recorded as the
        // website; the business, its place_id and its coordinates are untouched, and Website
        // Intelligence will look for a real site later exactly as it does for any lead without one.
        if (isset($row['website'])) {
            $classifier = app(WebsiteUrlClassifier::class);
            $url = (string) $row['website'];

            if (! $classifier->isOwnWebsiteCandidate($url)) {
                $column = $classifier->socialColumn($url);
                // Never overwrite a social handle the record already carries: a spreadsheet import or
                // a BD may have filled it in, and their value is the better-attested one.
                //
                // `socialColumn()` returns null when there is nowhere to file the URL — a TikTok
                // link, or a Facebook link-shim longer than the 255-char column. The URL is then
                // dropped rather than left in `website`, which is exactly what this path already
                // does for an over-long website URL: an unusable link is not worth recording as the
                // wrong kind of fact, and the business itself is kept in full either way.
                if ($column !== null && ! isset($row[$column])) {
                    $row[$column] = $url;
                }
                unset($row['website']);
            }
        }

        // Not a passthrough — a refusal. See the docblock.
        $row['website_status'] = 'website-unknown';

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
            // Resolved once, outside the loop: every note in one import shares the same author.
            // Only loaded if a row actually carries a comment.
            $author = null;

            foreach ($validRows as $data) {
                // The comment is NOT a lead attribute — `crm_leads` has no note column and
                // LeadCreator documents the same separation for the manual path. Pull it out
                // before the create so the row inserts exactly as it did before this mapping.
                $body = trim((string) ($data['note'] ?? ''));
                unset($data['note']);

                // LeadObserver records the "created" Activity automatically.
                $lead = Lead::create([...$data, 'created_by' => $userId, 'assigned_to' => $assignedTo]);
                $count++;

                if ($body === '') {
                    continue;
                }

                // Truncate rather than reject — see NOTE_MAX_LENGTH. mb_substr so a multibyte
                // comment can never be cut mid-character.
                $body = mb_substr($body, 0, self::NOTE_MAX_LENGTH);
                $author ??= User::find($userId);
                if ($author !== null) {
                    // Same service as the wizard and the Lead Details composer → same author
                    // association and the same `note.created` Activity via NoteObserver.
                    $this->noteCreator()->create($lead, $body, $author);
                }
            }

            return $count;
        });
    }

    /**
     * Reads the first sheet into rows of scalar cell values.
     *
     * @return list<list<string>>
     */
    private function readRows(string $path, string $extension): array
    {
        // Legacy `.xls` is normalized to `.xlsx` first and then read by the SAME OpenSpout reader as
        // a native `.xlsx` — one parsing path for both, so aliasing/translation/dedup cannot diverge.
        // The generated file is ours and is always removed, including when parsing throws.
        $converted = null;
        if ($extension === 'xls') {
            $converted = $this->xls->convert($path);
            $path = $converted;
            $extension = 'xlsx';
        }

        try {
            return $this->readRowsFrom($path, $extension);
        } finally {
            if ($converted !== null && is_file($converted)) {
                @unlink($converted);
            }
        }
    }

    /**
     * The unchanged OpenSpout read. `.xlsx` and CSV/TXT reach this exactly as they always did.
     *
     * @return list<list<string>>
     */
    private function readRowsFrom(string $path, string $extension): array
    {
        $reader = $extension === 'xlsx' ? new XlsxReader : new CsvReader;

        $reader->open($path);

        $rows = [];
        $dataRows = 0;
        try {
            foreach ($reader->getSheetIterator() as $sheet) {
                foreach ($sheet->getRowIterator() as $row) {
                    $cells = [];
                    foreach ($row->toArray() as $value) {
                        $cells[] = $this->stringify($value);
                    }

                    // Row cap ({@see MAX_IMPORT_ROWS}), counted as the sheet streams in so an over-cap file is
                    // refused before the rest of it is ever loaded. The first row is the header, and a wholly
                    // blank row is not data; both are still kept, so reported row numbers do not shift.
                    if ($rows !== [] && trim(implode('', $cells)) !== '' && ++$dataRows > self::MAX_IMPORT_ROWS) {
                        throw ValidationException::withMessages([
                            'file' => sprintf(
                                'This file has more than %s rows. Split it into smaller files.',
                                number_format(self::MAX_IMPORT_ROWS),
                            ),
                        ]);
                    }

                    $rows[] = $cells;
                }
                break; // MVP: first sheet only
            }
        } finally {
            $reader->close();
        }

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

    /**
     * Named header cells that matched no known CRM field — surfaced so an importer can see exactly
     * which spreadsheet columns were ignored (enterprise Smart Header Recognition). Blank header
     * cells are not reported (they are not real columns).
     *
     * @param  list<string>  $header
     * @param  array<string, int>  $map
     * @return list<string>
     */
    private function unknownColumns(array $header, array $map): array
    {
        $mappedIndexes = array_flip(array_values($map));

        $unknown = [];
        foreach ($header as $index => $cell) {
            if ($this->normalizeHeader($cell) === '') {
                continue; // blank header cell — not a column
            }
            if (isset($mappedIndexes[$index])) {
                continue; // recognized and mapped to a CRM field
            }
            $unknown[] = trim($cell);
        }

        return $unknown;
    }

    private function normalizeHeader(string $value): string
    {
        $value = strtolower(trim($value));
        // Strip apostrophes (straight + curly) so "Owner's Name" and "Owners Name" resolve identically.
        $value = str_replace(["'", "\u{2019}", "\u{2018}"], '', $value);

        // Collapse ALL separators — whitespace, underscores, and dashes — to a single space, so
        // "E-mail", "Email_Address", and "Email  Address" all normalize onto the same alias.
        return (string) preg_replace('/[\s_\-]+/', ' ', $value);
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
