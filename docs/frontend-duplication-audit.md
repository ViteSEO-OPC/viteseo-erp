# Frontend Component Duplication Audit

**Date:** 2026-07-15 · **Scope:** `frontend/src/` (React / Next.js 16) · **Status:** analysis only — no files changed.

This is a check-only audit of repeated code and near-duplicate components that could be
consolidated into shared primitives. Findings were verified by reading file contents, not
guessed from filenames.

## TL;DR — the foundations are already good

These shared primitives already exist and are widely adopted; the duplication is **not** here:

- `core/components/data-table.tsx` — used in 21 files; only 3 raw `<table>` left.
- `core/components/status-badge.tsx` (`StatusBadge` + central `STATUS_STYLES`).
- `core/components/{page-header,stat-card,avatar,review-dialog}.tsx`.
- `components/ui/{dialog,input,label,button,table}.tsx`.
- `core/lib/format.ts` — `formatPeso`, `formatDate`, `formatDateTime`, `formatTimeManila`.

**All remaining duplication is in the _composition layer_** — pages/dialogs/hooks that
re-assemble those primitives the same way. Priority is by ROI (reach × how cheap the fix is).

---

## Merged priority order

1. **`useReviewQueue` + action-column helper** (Cluster 1) — largest single win, ~7 copies.
2. **`applyServerErrors` util** (Cluster 3) + **`useCrudListPage`** (Cluster 2) — cover most HR forms/pages, both cheap.
3. **`<QueryState>` wrapper** (Cluster 5) — ~40 sites, low risk.
4. **`<Select>` + `<Field>` primitives** (Clusters 8 & 4) — also erases the 24× `selectClass` and the form-dialog scaffold.
5. **`createCrudHooks` factory** (Cluster 6) + **`<ReportShell>`** (Cluster 11) — biggest line reductions.
6. Cleanups: formatter reuse (7), `EmployeeCell`/`REJECT_BTN` (10 / 1), color-label maps (9), empty-state (12), bar charts (13).

Items 1–4 alone touch the majority of the app and remove well over 1,000 lines.

---

## Cluster 1 — Approve/Reject "review queue" 🔴 **biggest win**

**~7 near-identical implementations, ~150+ duplicated lines each.**

Every HR request queue re-implements the same machine: a list query with `isLoading/isError`,
a `review` state `{ mode: "approve" | "reject"; row }`, an `onConfirm(reason)` approve/reject
switch with toasts, a `busyId` guard, a `DataTable` whose last column renders Approve/Reject
buttons gated on `status === "pending"`, and a trailing `<ReviewDialog>`.

Files:
- `src/app/hr/requests/leave/page.tsx` — `TimeLeavePage` **and** `HalfDaySection` (two copies in one file)
- `src/app/hr/requests/document/page.tsx` — `DocumentQueue` **and** `ChangeQueue` (two more)
- `src/app/hr/requests/payroll/page.tsx` — `PayslipQueue` + `InquiryQueue`
- `src/app/hr/finance/reimbursement/page.tsx`
- `src/modules/hr/components/pending-approvals.tsx`

Identical: review state shape, `onConfirm` try/catch/toast, the `busyId` derivation
`(approveMut.isPending && approveMut.variables) || (rejectMut.isPending && rejectMut.variables?.id) || null`,
the Approve/Reject button pair, `needsAction={(r) => r.status === "pending"}`, the `ReviewDialog` block.
Differs: row type, columns, mutation hooks, toast copy.

Also duplicated verbatim: `const REJECT_BTN = "border-danger text-danger hover:bg-danger-subtle hover:text-danger";`
in both `requests/document/page.tsx:35` and `requests/payroll/page.tsx:33`.

**Fix:** a generic `<ReviewQueue query approveMutation rejectMutation columns subject />` component,
or a `useReviewQueue()` hook returning `{ review, setReview, onConfirm, busyId }` + a `reviewActionColumn()` helper.

---

## Cluster 2 — CRUD list page ("List + Add/Edit Dialog + Delete/Archive") 🟠

**6+ pages, ~120 lines of identical scaffolding each.**

`useX()` list query, `[dialogOpen, setDialogOpen]`, `[editing, setEditing]`, `openCreate()/openEdit()`,
`onDelete()` with `confirm(...)` + toast, a `PageHeader` with an "Add …" button, the inline
loading/error branch, a `DataTable`, and a `<XFormDialog open editing />`.

Files: `src/app/hr/company/departments/page.tsx` (canonical), `.../company/leave-types/page.tsx`,
`.../company/faq/page.tsx`, `.../company/holidays/page.tsx`, `.../finance/office-expenses/page.tsx`,
`.../company/benefits/page.tsx`. (12 `confirm()` call sites total across the app.)

**Fix:** a `useCrudListPage()` hook (dialog/edit/delete state) + a shared `confirmDelete()` helper.

---

## Cluster 3 — RHF+Zod `onSubmit` 422 server-error handler 🔴 **cheap, high count**

**7 verbatim copies** of:
```ts
if (err instanceof AxiosError && err.response?.status === 422) {
  const serverErrors = err.response.data?.errors ?? {};
  for (const field of Object.keys(serverErrors))
    setError(field as keyof FormValues, { message: serverErrors[field][0] });
} else { toast.error("Something went wrong. Please try again."); }
```
Files: `department-form-dialog.tsx:81`, `leave-type-form-dialog.tsx:100`, `claim-form.tsx`,
`employee-form-dialog.tsx`, `half-day-request-form.tsx`, `leave-request-form.tsx`, `benefit-program-form-dialog.tsx`
(all under `src/modules/hr/components/`).

**Fix:** an `applyServerErrors(err, setError, toast)` util; optionally a `useCrudForm({ schema, entity, createMut, updateMut, toDefaults })` hook that owns reset-on-open + submit switch + error handling.

---

## Cluster 4 — Form-dialog scaffolding (two competing styles) 🟠

**~12 dialogs.** All create/edit dialogs re-assemble the same shell
`Dialog > DialogContent > DialogHeader(Title/Description) > body > DialogFooter(Cancel + Submit)`
with an identical footer:
```tsx
<DialogClose asChild><Button variant="outline">Cancel</Button></DialogClose>
<Button type="submit" disabled={isSubmitting}>
  {isSubmitting ? "Saving…" : isEdit ? "Save changes" : "Create"}
</Button>
```
Files: `src/modules/hr/components/*-form-dialog.tsx` — department, leave-type, faq, holiday,
office-expense, work-schedule, requisition, candidate, benefit-program, employee.

**Two inconsistent implementations of the same idea:** RHF+Zod (department, leave-type, claim, employee)
vs. plain `useState` with a mounted-only `<Body key=… />` sub-component (`faq-form-dialog.tsx:35`,
`holiday-form-dialog.tsx:38`). Standardizing on one is itself a cleanup.

**Fix:** a `<FormDialog title description isEdit isSubmitting onSubmit>` shell + `<Select>` + `<Field>` (Cluster 8).

---

## Cluster 5 — Inline loading / error states 🟠 **~40 sites, low risk**

The ternary `isLoading ? <p className="text-sm text-muted-foreground">Loading…</p> : isError ?
<p className="text-sm text-destructive">Could not load…</p> : <DataTable/>` is hand-written in
virtually every page. Grep: **~42** muted "Loading" spans, **~30** destructive error lines. No shared
async wrapper. Copy drift already present ("Loading requests…" vs "Loading departments…").

**Fix:** a `<QueryState query loading error>{(data) => …}</QueryState>` wrapper (or `renderQuery(q, render)`).

---

## Cluster 6 — TanStack Query CRUD hook files 🟠

**~15 near-identical hook modules** under `src/modules/hr/hooks/`: `use-departments.ts`, `use-faqs.ts`,
`use-holidays.ts`, `use-office-expenses.ts`, `use-reimbursements.ts`, `use-leave-config.ts`,
`use-benefit-programs.ts`, … Each = a `KEY` tuple, `useX()` = `useQuery`, and
`useCreate/useUpdate/useDelete` = `useMutation` with `onSuccess: () => qc.invalidateQueries({ queryKey: KEY })`.
The `useUpdate*` signature `({ id, input }) => update(id, input)` is identical everywhere.

The parallel `src/modules/hr/api/*.ts` files are also repetitive thin
`apiClient.get/post/put/delete → res.data.data` wrappers (lower priority).

**Fix:** a `createCrudHooks({ key, api })` factory returning `{ useList, useCreate, useUpdate, useDelete }`.
Bespoke ones (holidays' nested `setId` keys, faqs' publish/pin) stay hand-written.

---

## Cluster 7 — Formatters reimplemented despite `core/lib/format.ts` 🟡

`format.ts` already exports `formatPeso`/`formatDate`/`formatDateTime`/`formatTimeManila`, yet ~8 spots
reinvent them inline — and they've drifted (`office-expenses` renders `₱ 1,000`, `formatPeso` renders `₱1,000.00`):
- `src/app/hr/finance/office-expenses/page.tsx:42` — local `peso()`
- `src/modules/hr/components/employee-record.tsx:618` — inline month/year date
- `src/modules/hr/components/attendance-adjust-dialog.tsx:55` — inline date
- `src/modules/hr/components/grace-period-button.tsx:67`, `.../time-clock-panel.tsx:70`, `src/app/(employee)/page.tsx:74` — inline date/time
- `src/app/hr/requests/payroll/page.tsx:39` — bespoke `turnaround()` duration formatter

**Fix:** call the existing helpers; add `formatMonthYear()` and `formatDuration()` to `format.ts`.

---

## Cluster 8 — Form-field wrapper (Label + Input + error) not shared 🟡

The triple `<div className="space-y-1.5"><Label/><Input/>{errors.x && <p className="text-xs text-destructive">…</p>}</div>`
repeats dozens of times across every form dialog. `employee-form-dialog.tsx:583` already factored a **local**
`Field` — proof the abstraction is wanted but never promoted.

Related: **no shared `<Select>` primitive exists** even though `input/label/button` do. This is the root cause
of `const selectClass = "h-9 rounded-lg border …"` being **copy-pasted in 24 files** (all 8 reports, most
form-dialogs, several pages) — with values already drifting (`bg-transparent px-2.5` vs `bg-card px-3`).

**Fix:** promote a single `<Field label htmlFor error>` (+ `<FieldError>`) and add a `<Select>` to `components/ui/`.

---

## Cluster 9 — Local status/label/color maps outside `StatusBadge` 🟡

Despite the central `STATUS_STYLES` in `status-badge.tsx`, feature pages define their own
`Record<string, string>` maps:
- `app/hr/requests/document/page.tsx:27` `DOC_STATUS_LABEL`
- `app/hr/requests/payroll/page.tsx:36` `PAYSLIP_STATUS_LABEL`
- `app/hr/company/benefits/page.tsx:23` `GOV_COLOR`, `:30` `CATEGORY_LABEL`
- `app/hr/company/faq/page.tsx:27` `CATEGORY_STYLE`
- `app/hr/company/holidays/page.tsx:32` `TYPE_STYLE`, `:37` `TYPE_SHORT_LABEL`
- `modules/hr/components/audit-report.tsx:26` `MODULE_STYLE`

Status-label overrides are what `StatusBadge`'s `label` prop already handles; the color maps are the
same "key→tailwind color" idea reimplemented.

**Fix:** a generic `<Pill colorKey map />` / `useColorMap(map)`; fold status-label overrides into a per-resource label map passed to `StatusBadge`.

---

## Cluster 10 — Avatar + name "EmployeeCell" 🟡

The `<div className="flex items-center gap-…"><Avatar name colorSeed size="sm"/><span className="font-semibold">{name}</span></div>`
cell is inlined in ~10 files (`requests/leave`, `requests/payroll` — which already extracted a **local**
`EmployeeCell` at `page.tsx:51` — `finance/reimbursement`, `assign-benefits-dialog`, `candidate-detail-dialog`,
`candidates-table`, `employee-card`, `checkin-board`, `employee-record`).

**Fix:** promote the local `EmployeeCell` into `core/components/` and reuse.

---

## Cluster 11 — The 8 `*-report.tsx` are one component wearing 8 hats 🟠

`attrition/audit/compensation/diversity/headcount/health-safety/leave/recruitment-report.tsx`
(~100 lines each, ~830 total) are near-identical: same imports, same `selectClass`, same duplicated
`RANGES` array, same `range`/`department` state, same `useReport(key)`/`useExportReport(key)`, same
`onExport()` + toast, same `PageHeader` + back-link + Download button. Only the report key, chart
component, and series colors differ.

**Fix:** one `<ReportShell reportKey chart colors>` wrapper; each report drops to ~15 lines. Kills ~600 lines.

---

## Cluster 12 — Dashed empty-state re-implemented ~9× 🟡

The `rounded-card border border-dashed … text-center py-16` "nothing here yet" block is hand-written in
`payslips`, `benefits`, `holidays`, `recruitment`, `payroll` pages + `candidate-form`, `claim-form`,
`assign-benefits` dialogs.

**Fix:** an `<EmptyState icon title action />` in `core/components/`.

---

## Cluster 13 — Bar-chart variants likely collapsible 🟡 (verify first)

`grouped-bar-chart.tsx`, `stacked-bar-chart.tsx`, `horizontal-stacked-chart.tsx` (~75–100 lines each) are
three takes on the same SVG/flex bar primitive differing mostly by orientation/stacking.

**Fix:** possibly one `<BarChart variant orientation />` — but read closely before merging; charts are the
one place premature abstraction hurts.
