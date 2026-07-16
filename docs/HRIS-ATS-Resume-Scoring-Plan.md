# HRIS ATS — Resume Scoring & Ranking (non-AI)

> **What this document is.** An implementer-ready build spec for adding **resume scoring + candidate ranking** to the existing Recruitment module. It is written to be handed to an AI coding agent as its instructions. It follows the same standards, envelope, and per-slice protocol as `docs/HRIS-BUILD-PLAN.md` — read that plan's §5.5 (cross-cutting mechanics), §7 (standards), and §11 (per-slice protocol) before starting. This is a **new slice** layered on top of **Slice 10 (Recruitment)**, which is already built.
>
> **Non-negotiable framing:** this is a **deterministic, non-AI** scorer (keyword + optional TF-IDF math). **No LLM, no Ollama, no external calls.** Every point of a candidate's score must be explainable from the resume text and the job description. Scores are **advisory** — the system **never auto-rejects** anyone.

---

## 0. The workflow we are building (HR's mental model)

```
1. HR creates/edits a Requisition and pastes the JOB DESCRIPTION + required skills there.
        └─ this is the single source of truth the scorer ranks against.
2. HR clicks "Add Candidate", fills their info, and uploads a PDF resume.
        └─ on save: file stored → text extracted → scored vs the requisition → score saved.
3. New candidates land in a RANKED APPLICANTS TABLE (stage = "applied"), sorted by score DESC.
        └─ each row shows the match score + a "why" breakdown (which keywords hit/missed).
4. HR reviews the table and DRAGS the good ones into the KANBAN board, which now
   starts at SCREENING (applied is the table, no longer a board column).
        └─ the @dnd-kit board already exists; applied→screening is an existing moveStage() transition.
```

So the scorer's only job is to **rank the "applied" pool** so HR knows who to pull into the pipeline. Everything downstream (screening → interview → offer → hired → draft-employee handoff) is **already built and unchanged**.

---

## 1. Hard rules (inherit from `CLAUDE.md` — do not deviate)

- **Laravel is the only backend.** All parsing/scoring logic lives in `app/Modules/Hr/Services/`. No scoring or file logic in Next.js.
- **`hr_` table prefix**, plural snake_case. Money is irrelevant here. Soft-delete stays on `hr_candidates`/`hr_requisitions`.
- **Envelope `{ data: ... }`** via API Resources; errors `{ message, errors }`. Endpoints under `/api/v1/hr/...`, all behind `auth:sanctum` + `role:hr_officer` (reuse the existing group in `Routes/api.php`).
- **Permissions** reuse `hr.recruitment.view` / `hr.recruitment.manage`. ATS settings edits require `hr.recruitment.manage`.
- **No new frontend libraries** without approval. `@dnd-kit` is already in. For the keyword tag-input use plain inputs — do **not** add a tag library.
- **TypeScript only.** `.tsx` for components, `.ts` for logic.
- **One dependency needs approval — see §9.** Have the manual-paste fallback ready so the build is never blocked on it.

---

## 2. Scope — MVP vs. explicitly OUT

**In scope (one-day MVP):**
- Job description + required/nice-to-have keywords + min-experience on the requisition.
- PDF resume upload on candidate create; server-side text extraction.
- Deterministic keyword-coverage score (0–100) with a stored, itemized breakdown.
- Ranked applicants table (the "applied" pool), sorted by score, with a "why this score" panel.
- Configurable ATS options (weights, keywords, threshold) — global defaults **and** per-requisition overrides.
- Rescore action (per candidate and per requisition) for when the JD/config changes.

**Out of scope (do NOT build; note as stretch):**
- ❌ Any AI/LLM/embedding model.
- ❌ Auto-reject or hard gating on score. Scores are advisory only.
- ❌ OCR / scanned-image PDFs (text-layer PDFs only).
- ❌ DOCX parsing (PDF only for MVP; DOCX is a stretch — see §9).
- ❌ A public careers page / candidate self-apply. HR uploads on the candidate's behalf.
- ❌ Drag-and-drop rework — the board already works; we only add the ranked table as a new drag source.

---

## 3. Data model changes

Three migrations (new timestamped files, `hr_` prefix, follow existing migration style). **Add columns; do not rewrite existing tables.**

### 3.1 `hr_requisitions` — add the job-description + scoring signal

| Column | Type | Notes |
|---|---|---|
| `description` | `text` nullable | The job description prose. The human-readable JD + the text used by the optional TF-IDF component. |
| `required_keywords` | `json` nullable | Must-have skills. Array of `{ "term": string, "weight": number }` (weight default 1). Plain `["php","laravel"]` also accepted and normalized to weight 1 on read. |
| `nice_to_have_keywords` | `json` nullable | Bonus skills, same shape. |
| `min_experience_years` | `unsignedTinyInteger` nullable | Used by the experience component. Null = not scored on experience. |
| `scoring_config` | `json` nullable | Per-requisition **override** of the global ATS settings (§5). Null = inherit global defaults. |

### 3.2 `hr_candidates` — add resume + score

| Column | Type | Notes |
|---|---|---|
| `resume_path` | `string` nullable | Path on the `public` disk (like `receipt_path` on reimbursements). |
| `resume_text` | `longText` nullable | Extracted plain text (also the manual-paste fallback target). |
| `match_score` | `decimal(5,2)` nullable | 0.00–100.00. Null = not yet scored. |
| `score_breakdown` | `json` nullable | Itemized explanation (schema in §4.3). Includes a snapshot of the config used. |
| `scored_at` | `timestamp` nullable | When the score was last computed. |

### 3.3 New table `hr_ats_settings` — global defaults (single row)

A one-row settings table HR can edit (the "flexibility" layer). Seed exactly one row.

| Column | Type | Default |
|---|---|---|
| `id` | id | |
| `weight_required` | `unsignedSmallInteger` | `65` |
| `weight_nice_to_have` | `unsignedSmallInteger` | `15` |
| `weight_experience` | `unsignedSmallInteger` | `20` |
| `weight_jd_similarity` | `unsignedSmallInteger` | `0` (TF-IDF component off by default — see §4.2) |
| `shortlist_threshold` | `unsignedTinyInteger` | `60` |
| `match_whole_word` | `boolean` | `true` |
| `enable_stemming` | `boolean` | `false` |
| timestamps | | |

> The four `weight_*` values are **relative**; the scorer normalizes them so only *active* components sum to 100 (§4.2). HR does not have to make them add to 100.

**Models:** add the new columns to `Requisition`/`Candidate` `$fillable` + `$casts` (`required_keywords`, `nice_to_have_keywords`, `scoring_config`, `score_breakdown` → `array`; `min_experience_years` → `integer`; `match_score` → `decimal:2`; `scored_at` → `datetime`). Create `AtsSetting` model (`protected $table = 'hr_ats_settings'`) with a `AtsSetting::current()` helper returning the single row.

---

## 4. The scoring engine (`ResumeScoringService`) — the heart of this slice

New service `app/Modules/Hr/Services/ResumeScoringService.php`. **Pure and deterministic** — same inputs always produce the same score. No DB writes inside the scoring method itself (it takes text + config, returns a result array); the caller persists.

### 4.1 Normalization (applied to resume text AND every keyword)
1. Lowercase.
2. Replace any non-alphanumeric run with a single space; trim.
3. Tokenize on spaces → a token list; also keep the normalized full string for phrase matching.
4. If `enable_stemming` is true, apply a **simple** suffix strip (`ing`/`ed`/`es`/`s`) to each token. Default off for predictability.

### 4.2 Components & weighting

Compute each component as a fraction in `[0,1]`, then weight it. **Only active components count**, and their weights are renormalized to sum to 100 so scores stay comparable:
- A keyword component is **active** if its keyword list is non-empty.
- Experience is active if `min_experience_years` is set (> 0).
- JD similarity is active if `weight_jd_similarity > 0` **and** `description` is non-empty.

```
active = components with (weight > 0 AND has-required-input)
factor = 100 / sum(weight_i for i in active)      // renormalize
total  = round( Σ  (weight_i * factor) * fraction_i , 2 )   // over active components
```

**Component fractions:**

- **Required keywords** — for each keyword, matched if its normalized form is present (whole-word token membership for single words; substring on the normalized string for multi-word phrases). `fraction = Σ weight(matched) / Σ weight(all required)`.
- **Nice-to-have** — same matching; `fraction = Σ weight(matched) / Σ weight(all nice)`.
- **Experience** — `fraction = min(1, candidate.experience_years / min_experience_years)` (0 if candidate years null).
- **JD similarity (optional, default weight 0)** — bag-of-words **TF cosine similarity** between the normalized JD (`description`) and the normalized resume text, over the union vocabulary (drop a short English stop-word list). Keep it a plain TF cosine — do **not** compute IDF across a changing candidate corpus (unstable scores). This is the only "fuzzy" component; it's off by default because it's the least explainable. Document it as opt-in.

### 4.3 Output — `score_breakdown` JSON (this is the "why")

The scorer returns and the caller stores exactly this shape so the UI can render the explanation without recomputing:

```json
{
  "total": 82.5,
  "shortlisted": true,
  "components": [
    { "key": "required_keywords", "label": "Required skills", "weight": 68.4, "fraction": 0.8,
      "earned": 54.7,
      "matched": ["php", "laravel", "mysql", "rest api"],
      "missing": ["redis", "docker"] },
    { "key": "nice_to_have", "label": "Nice to have", "weight": 15.8, "fraction": 1.0,
      "earned": 15.8, "matched": ["vue"], "missing": [] },
    { "key": "experience", "label": "Experience", "weight": 15.8, "fraction": 0.6,
      "earned": 9.5, "required_years": 5, "candidate_years": 3 }
  ],
  "config_snapshot": { "weight_required": 65, "weight_nice_to_have": 15, "weight_experience": 20,
                       "weight_jd_similarity": 0, "shortlist_threshold": 60,
                       "match_whole_word": true, "enable_stemming": false },
  "scored_against_requisition_id": 12
}
```

- `weight`/`earned` are the **renormalized, post-`factor`** numbers so they visibly sum to `total`.
- `shortlisted = total >= shortlist_threshold`.
- `config_snapshot` freezes the effective config used, so a later settings change doesn't silently rewrite an old candidate's displayed reasons — the score only changes on an explicit **rescore**.

### 4.4 Config resolution
Effective config = `AtsSetting::current()` merged with the requisition's `scoring_config` override (override wins per-key). Build one `resolveConfig(Requisition)` helper and use it everywhere (scoring + display).

---

## 5. Wiring into `RecruitmentService`

- **`createCandidate`** — accept an optional `UploadedFile $resume` and optional `resume_text` (manual paste). If a file is given, store it (`$resume->store('resumes', 'public')`, mirror `ReimbursementService::create`) and extract text via `ResumeParser` (§9). Then call `ResumeScoringService`, and persist `resume_path`, `resume_text`, `match_score`, `score_breakdown`, `scored_at`. If neither file nor text is present, save the candidate unscored (`match_score = null`) — scoring is not mandatory.
- **`rescoreCandidate(Candidate)`** — recompute from the stored `resume_text` against the current requisition + config; persist. No-op with a clear error if `resume_text` is empty.
- **`rescoreRequisition(Requisition)`** — loop its candidates that have `resume_text` and rescore each (use for "I edited the JD/keywords, re-rank everyone").
- **ATS settings**: `getAtsSettings()` / `updateAtsSettings(array)` on a small `AtsSettingsService` (or on `RecruitmentService`).

Keep the existing `moveStage`, `hire`, requisition CRUD **unchanged**.

---

## 6. HTTP layer

**Requests:**
- `StoreRequisitionRequest` — add: `description` `nullable|string|max:20000`; `required_keywords`/`nice_to_have_keywords` `nullable|array` (+ `.*.term` string, `.*.weight` `numeric|min:0` — also accept a plain string array and normalize); `min_experience_years` `nullable|integer|min:0|max:60`; `scoring_config` `nullable|array`.
- `StoreCandidateRequest` — add: `resume` `nullable|file|mimes:pdf|max:5120` (mirror the reimbursement receipt rule); `resume_text` `nullable|string|max:50000`. **Note:** candidate create becomes `multipart/form-data` when a file is attached.
- New `UpdateAtsSettingsRequest` — weights `integer|min:0|max:100`, `shortlist_threshold` `integer|min:0|max:100`, booleans `boolean`.

**Controller (`RecruitmentController`)** — thin, delegate to services. Add:
- `rescoreCandidate(Candidate)` → `CandidateResource`.
- `rescoreRequisition(Requisition)` → returns the recomputed `CandidateResource::collection`.
- `atsSettingsShow()` / `atsSettingsUpdate(UpdateAtsSettingsRequest)`.
- Update `storeCandidate` to pass `$request->file('resume')` into the service (like `ReimbursementController` passes the receipt).

**Routes** (inside the existing `role:hr_officer` group in `app/Modules/Hr/Routes/api.php`):
```
Route::post('candidates/{candidate}/rescore',   [RecruitmentController::class, 'rescoreCandidate']);
Route::post('requisitions/{requisition}/rescore',[RecruitmentController::class, 'rescoreRequisition']);
Route::get ('ats-settings', [RecruitmentController::class, 'atsSettingsShow']);
Route::match(['put','patch'],'ats-settings', [RecruitmentController::class, 'atsSettingsUpdate']);
```

**Resources:**
- `CandidateResource` — add `resume_url` (`$this->resume_path ? Storage::disk('public')->url($this->resume_path) : null`), `match_score` (float|null), `score_breakdown` (array|null), `scored_at`. **Do not** expose `resume_text` in lists (large); expose it only if needed for a detail view.
- `RequisitionResource` — add `description`, `required_keywords`, `nice_to_have_keywords`, `min_experience_years`, `scoring_config`.
- New `AtsSettingResource` for the settings row.

---

## 7. Frontend

Mirror the existing module structure (`modules/hr/{types,api,hooks,components}` + page under `app/hr/people/recruitment`).

**Types (`types/recruitment.ts`):**
- Extend `Requisition` with `description`, `required_keywords`, `nice_to_have_keywords`, `min_experience_years`, `scoring_config`.
- Extend `Candidate` with `resume_url: string | null`, `match_score: number | null`, `score_breakdown: ScoreBreakdown | null`, `scored_at: string | null`. Add a `ScoreBreakdown` interface matching §4.3.
- Add `AtsSettings` type.
- **Change `KANBAN_STAGES`**: remove `"applied"` — the board now starts at `screening`. (`MOVE_STAGES` keeps `applied` since that's the drag *target* when promoting from the table back, and the drag *source* stage.)

**API (`api/recruitment.ts`):**
- `createCandidate` → build a `FormData` when a resume file is present (`multipart/form-data`); keep JSON when not. Follow the reimbursement upload call.
- Add `rescoreCandidate(id)`, `rescoreRequisition(id)`, `getAtsSettings()`, `updateAtsSettings(input)`. Extend `RequisitionInput` with the JD/keyword/config fields.

**Hooks (`hooks/use-recruitment.ts`):**
- Update `useCreateCandidate` for FormData.
- Add `useRescoreCandidate`, `useRescoreRequisition` (both invalidate `["hr","candidates"]`), `useAtsSettings`, `useUpdateAtsSettings`.

**Components:**
1. **`requisition-form-dialog`** — add a **Job Description** `<textarea>`, a **Required skills** and **Nice-to-have** entry (comma/enter-separated inputs → string arrays; no tag lib), and a **Min experience (yrs)** number. Put weight/threshold overrides behind a collapsible **"Advanced ATS scoring"** section (leave blank = inherit global defaults). On save, if editing an existing req with candidates, offer "Re-rank candidates now" → `useRescoreRequisition`.
2. **`candidate-form-dialog`** — add a **PDF resume** file input (`accept="application/pdf"`), and a collapsible **"Paste resume text instead"** `<textarea>` fallback. Submit via FormData.
3. **`ScoreBadge`** (new) — a pill showing `match_score` rounded, tone by `shortlisted` (brand/success if ≥ threshold, muted/warning if below, neutral "Not scored" if null). Reuse existing badge/token styles — no raw hexes.
4. **`ScoreBreakdownPopover`** (new) — click the badge to reveal the §4.3 components: matched keywords (as success chips), missing (as muted chips), experience line, and the total. This is the **"why this score"** the user asked for.
5. **`RankedApplicantsTable`** (new) — the "applied" pool. Columns: candidate (avatar+name), source, experience, **score badge (sortable, default DESC)**, resume link (`resume_url`), and an action to move into the pipeline. Because the `@dnd-kit` board already exists, make each **row a `useDraggable`** with the candidate id, so HR can drag a row into the existing `screening` droppable column. **Also** give each row a plain **"Move to Screening"** button (accessibility + non-drag fallback) that calls `useMoveCandidate({ id, stage: "screening" })`.

**Page (`app/hr/people/recruitment/page.tsx`):**
- Render `RankedApplicantsTable` (candidates with `stage === "applied"`, sorted by `match_score ?? -1` DESC) **above** the kanban, under a "Ranked Applicants" heading.
- The `DndContext` must wrap **both** the table and the board so a row can be dragged into a column (the board's `useDroppable` columns already accept it; `onDragEnd` already reads `over.id` as the target stage — it will just work for `applied → screening`).
- Kanban renders `KANBAN_STAGES` (now screening → hired). The hired-column handoff logic stays exactly as-is.

**ATS settings UI** — a small dialog or a section on the recruitment page (gear button) bound to `useAtsSettings`/`useUpdateAtsSettings`: the four weights, threshold, whole-word + stemming toggles. This is the global "flexibility" surface; per-requisition overrides live in the requisition dialog's Advanced section.

---

## 8. Seeder updates

Update `RecruitmentDemoSeeder`:
- Give the demo requisitions a realistic `description` + `required_keywords` / `nice_to_have_keywords` / `min_experience_years` (e.g. a "Senior Laravel Engineer" req: required `["php","laravel","mysql","rest api"]`, nice `["vue","docker","redis"]`, min 4).
- For the demo candidates, set a plausible `resume_text` blob per candidate (no real files needed) and run them through `ResumeScoringService` in the seeder so `match_score` + `score_breakdown` are populated and the ranked table demos out of the box.
- Seed the single `hr_ats_settings` row with the §3.3 defaults.

---

## 9. The one dependency (get approval first) + fallback

Text extraction needs a PHP PDF parser: **`smalot/pdfparser`** (`composer require smalot/pdfparser`). Per build-plan §0.5, a new dependency needs CTO sign-off. Isolate it in a `ResumeParser` service (`extractText(UploadedFile): string`) so it's swappable.

**If approval is pending or a PDF won't parse, the build is NOT blocked:** the candidate dialog's **"Paste resume text instead"** textarea feeds `resume_text` directly, and scoring runs on that identically. Ship the paste path first; the PDF parser is an enhancement on top. Scanned/image PDFs (no text layer) → surface a friendly "couldn't read this PDF, paste the text" message; do not OCR. **DOCX** = stretch only (would need a second lib, e.g. `phpoffice/phpword`).

---

## 10. Guardrails (must hold)

- **Advisory, never automatic.** Score sorts and flags; it must never move, reject, or hide a candidate on its own. A human always decides.
- **Deterministic & explainable.** No randomness, no model. Every displayed number traces to a matched/missing keyword or the experience ratio.
- **Config changes don't silently rewrite history.** Editing global settings or a JD changes future scores; existing candidates only change on an explicit rescore. `config_snapshot` proves what each score was computed against.
- **Resume files** on the `public` disk under `resumes/`, PDF-only, ≤ 5 MB (same rule as receipts). Don't leak `resume_text` into list payloads.
- **No AI.** If you're reaching for an LLM/embedding/external API, stop — that's a different, separately-approved feature.

---

## 11. Build order (per-slice protocol, plan §11)

1. Migrations (3) + model updates → `php artisan migrate:fresh --seed` clean.
2. `ResumeScoringService` (+ a couple of `php artisan tinker`/unit checks on known inputs → known scores).
3. `ResumeParser` (behind approval; paste-fallback works without it).
4. `RecruitmentService` wiring (create-with-resume, rescore ×2, ATS settings) + requests + controller + routes.
5. Resource fields. **Verify with `curl`**: create a requisition with keywords, add a candidate with `resume_text`, confirm `match_score` + `score_breakdown` come back in the envelope.
6. Seeder updates → reseed → confirm ranked demo data.
7. Frontend: types → api → hooks → ScoreBadge/Popover → RankedApplicantsTable → dialogs → page wiring → ATS settings UI.
8. **Verify in browser**: JD+keywords on a req → add candidate with pasted resume → appears ranked in the table with a correct, explainable score → drag row into Screening → it enters the existing pipeline. Edit the JD → rescore → ranking updates.
9. `git commit`, then **STOP** for the user's review before anything else.

**Done when:** HR can put a job description + required skills on a requisition, add a candidate with a resume (PDF or pasted), see that candidate ranked by an **explainable** score in the applicants table, tune the scoring weights/threshold, and drag the strong matches into the (existing) kanban starting at Screening — with **zero AI** and no candidate ever auto-rejected.
