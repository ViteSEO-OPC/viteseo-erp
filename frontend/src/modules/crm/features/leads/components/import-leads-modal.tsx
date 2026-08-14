"use client";

import { useRef, useState } from "react";
import {
  Dialog,
  DialogContent,
  DialogHeader,
  DialogFooter,
  DialogTitle,
  DialogDescription,
} from "@/modules/crm/components/ui/dialog";
import { Button } from "@/modules/crm/components/ui/button";
import { Loader2, X } from "lucide-react";
import { SectionHeader } from "@/modules/crm/components/shared/section-header/section-header";
import { KpiStatCard } from "@/modules/crm/features/dashboard/components/kpi-stat-card";
import { AlertBanner } from "@/modules/crm/components/shared/alert-banner/alert-banner";
import { MappingTable } from "@/modules/crm/features/leads/components/mapping-table";
import { ImportStages } from "@/modules/crm/features/leads/components/import-stages";
import type { ImportStage } from "@/modules/crm/features/leads/components/import-stages";
import { UploadDropzone } from "@/modules/crm/components/forms/file-upload/upload-dropzone";
import { UploadProgressItem } from "@/modules/crm/components/forms/file-upload/upload-progress-item";
import { FormField } from "@/modules/crm/components/forms/form-field/form-field";
import { RelationshipSelect } from "@/modules/crm/components/shared/relationship-select/relationship-select";
import { useBdUsers } from "@/modules/crm/hooks/use-bd-users";
import { usePermissions } from "@/modules/crm/providers/permissions-provider";
import { useImportLeads, useImportJobStatus } from "@/modules/crm/features/leads/hooks/use-lead-mutations";
import type { KpiStat } from "@/modules/crm/features/dashboard/types/dashboard-types";
import type { ImportResult, ImportSummary } from "@/modules/crm/features/leads/types/lead-types";

/**
 * Import counts, straight from the validation response.
 *
 * No `delta` is passed: these are absolute counts for one file, not a trend, and KpiStat documents
 * delta as "only present when backed by a real trend source (never fabricated)". The previous flat
 * placeholder rendered an empty indicator row on every card, which cost vertical space in the dialog
 * and said nothing.
 */
function buildStats(summary: ImportSummary): KpiStat[] {
  return [
    { id: "total", label: "TOTAL ROWS", value: String(summary.total), valueVariant: "neutral" },
    // "Ready to Import" is the count the primary action commits — same number as the button label.
    { id: "valid", label: "READY TO IMPORT", value: String(summary.valid), valueVariant: "accent" },
    { id: "duplicates", label: "SKIPPED (DUPLICATE)", value: String(summary.duplicates), valueVariant: "neutral" },
    { id: "errors", label: "ERRORS", value: String(summary.errors), valueVariant: "neutral" },
  ];
}

/** Upload → analysis → commit. Every transition is driven by a real event, never a timer. */
// `converting` is the ONLY addition: a large legacy .xls is normalized to .xlsx on the queue
// before its preview exists. Every other format skips straight from `analyzing` to `preview`.
type ImportPhase = "empty" | "uploading" | "analyzing" | "converting" | "preview" | "importing";

/**
 * Derive the stage list from what has actually happened.
 *
 * Before the response exists the client knows only two things — how much of the file has been sent,
 * and that it is waiting — so it reports exactly those. Afterwards the stages the server demonstrably
 * ran are marked done and annotated with the counts it returned, so every figure on screen traces
 * back to the response body.
 */
function buildStages(
  phase: ImportPhase,
  progress: number,
  result: ImportResult | null,
): ImportStage[] {
  if (!result) {
    return [
      {
        id: "upload",
        label: "Uploading file",
        state: phase === "uploading" ? "active" : "done",
        detail: phase === "uploading" ? `${progress}%` : undefined,
      },
      {
        id: "analyze",
        // One step, because the server does all of this inside a single request (see ImportStages).
        label: "Reading, validating and de-duplicating",
        state: phase === "analyzing" ? "active" : "pending",
      },
    ];
  }

  const { summary, mapping } = result;
  const ignored = result.unknownColumns?.length ?? 0;

  const stages: ImportStage[] = [
    { id: "upload", label: "Uploaded file", state: "done" },
    { id: "read", label: "Read workbook", state: "done", detail: `${summary.total} rows` },
    {
      id: "map",
      label: "Mapped columns",
      state: "done",
      detail: ignored > 0 ? `${mapping.length} mapped · ${ignored} ignored` : `${mapping.length} mapped`,
    },
    {
      id: "validate",
      label: "Validated rows",
      state: "done",
      detail: summary.errors > 0 ? `${summary.errors} with errors` : "no errors",
    },
    {
      id: "dedupe",
      label: "Detected duplicates",
      state: "done",
      detail: summary.duplicates > 0 ? `${summary.duplicates} to skip` : "none found",
    },
  ];

  if (phase === "importing") {
    stages.push({
      id: "import",
      label: "Importing records",
      state: "active",
      detail: `${summary.valid} lead${summary.valid === 1 ? "" : "s"}`,
    });
  }

  return stages;
}

function formatBytes(bytes: number): string {
  if (bytes < 1024) return `${bytes} B`;
  const kb = bytes / 1024;
  if (kb < 1024) return `${kb.toFixed(1)} KB`;
  return `${(kb / 1024).toFixed(1)} MB`;
}

type Props = {
  open: boolean;
  onOpenChange: (open: boolean) => void;
};

export function ImportLeadsModal({ open, onOpenChange }: Props) {
  // Opens on the empty (dropzone) state per Import-Leads-Design-Specification §8 / §8.1
  // ("on open, focus the dialog/dropzone"; focus order step 2 = Dropzone).
  const [phase, setPhase] = useState<ImportPhase>("empty");
  const [progress, setProgress] = useState(0);
  const [file, setFile] = useState<File | null>(null);
  const [localResult, setResult] = useState<ImportResult | null>(null);
  // Owning BD for the imported leads. Admin/SA pick it; a BD's own import auto-assigns to
  // themselves (backend), so the selector is hidden for BDs.
  const [assignedTo, setAssignedTo] = useState<string>("");
  // Set only when the server answers 202 (large .xls routed to the queue). While it is set the
  // status hook polls; the commit then sends this id instead of re-uploading the workbook.
  const [jobId, setJobId] = useState<string | null>(null);

  const { hasMinRole } = usePermissions();
  const isManager = hasMinRole("crm_admin");
  const importLeads = useImportLeads();
  const jobStatus = useImportJobStatus(jobId);
  // Only Admin/SA need the BD list (BDs lack users.view — avoid a 403).
  const { bdUsers, isLoading: bdLoading } = useBdUsers(isManager);
  const abortRef = useRef<AbortController | null>(null);

  const resetState = () => {
    abortRef.current?.abort();
    setJobId(null);
    abortRef.current = null;
    setPhase("empty");
    setProgress(0);
    setFile(null);
    setResult(null);
    setAssignedTo("");
  };

  const handleOpenChange = (next: boolean) => {
    if (!next) resetState();
    onOpenChange(next);
  };

  const handleFile = async (selected: File) => {
    setFile(selected);
    setProgress(0);
    setPhase("uploading");
    const controller = new AbortController();
    abortRef.current = controller;
    try {
      const res = await importLeads.mutateAsync({
        file: selected,
        dryRun: true,
        assignedTo: assignedTo || undefined,
        onUploadProgress: (percent: number) => {
          setProgress(percent);
          // Every byte is sent, so the server is now doing the work. A real transition — the phase
          // flips on the transfer completing, not on a timer.
          if (percent >= 100) setPhase("analyzing");
        },
        signal: controller.signal,
      });
      if ("queued" in res) {
        // Too large to convert inside the request. The job id is now the handle for both the
        // preview (polled below) and the eventual commit — the workbook is converted only once.
        setJobId(res.jobId);
        setPhase("converting");
      } else {
        setResult(res);
        setPhase("preview");
      }
    } catch {
      // Cancelled or failed (error toast handled in the hook). Only reset if this
      // upload is still the active one (a cancel already resets and clears the ref).
      if (abortRef.current === controller) {
        setPhase("empty");
        setFile(null);
      }
    } finally {
      if (abortRef.current === controller) abortRef.current = null;
    }
  };

  const handleCancelUpload = () => {
    abortRef.current?.abort();
    abortRef.current = null;
    setPhase("empty");
    setProgress(0);
    setFile(null);
  };

  const handleImport = async () => {
    if (!file) return;
    setPhase("importing");
    try {
      // A queued .xls commits by id — the server still holds the converted copy, so a 10 MB
      // workbook is never uploaded or converted twice. Everything else re-sends the file as before.
      await importLeads.mutateAsync(
        jobId
          ? { jobId, dryRun: false, assignedTo: assignedTo || undefined }
          : { file, dryRun: false, assignedTo: assignedTo || undefined },
      );
      handleOpenChange(false); // success toast handled in the hook
    } catch {
      // Commit failed; toast shown by the hook. Return to preview so the user can retry.
      setPhase("preview");
    }
  };

  // DERIVED, not synced. A queued .xls has no local `result` until its conversion finishes, so the
  // polled preview is folded in here and the existing preview/commit JSX below renders unchanged.
  // Deriving avoids a setState-in-effect (React 19 lint) and keeps one source of truth per value.
  const queuedResult = jobStatus.data?.status === "ready" ? jobStatus.data.result : null;
  const jobFailed = jobStatus.data?.status === "failed";
  const result = localResult ?? queuedResult;
  const step: ImportPhase = phase === "converting" && queuedResult ? "preview" : phase;

  const validCount = result?.summary.valid ?? 0;
  const stages = buildStages(step, progress, result);
  /**
   * The stage list reports work in progress, so it is shown only while work is happening. In the
   * settled preview state every figure it carried is already on screen in the place it belongs —
   * rows in TOTAL ROWS, errors in ERRORS, duplicates in SKIPPED, mapped columns in the Column Mapping
   * caption, and the file itself in the upload row — so repeating them pushed the mapping table and
   * the primary action below the fold on a 1366×768 laptop for no added information.
   */
  const showStages = step === "uploading" || step === "analyzing" || step === "importing";

  return (
    <Dialog open={open} onOpenChange={handleOpenChange}>
      <DialogContent
        showCloseButton={false}
        // Wider from `lg` up: the preview's four cards and the two-column mapping table are the
        // widest content here, and giving them horizontal room is what shortens the scroll on a
        // 1366×768 laptop. Below `lg` the dialog keeps its existing 520px width.
        className="flex max-h-[90vh] w-full flex-col gap-0 p-0 sm:max-w-[520px] lg:max-w-[620px]"
      >
        {/* Header */}
        <DialogHeader className="border-b border-border-subtle px-6 py-4">
          <div className="flex items-start justify-between">
            <div>
              <DialogTitle className="text-xl font-bold text-card-foreground">
                Import Leads
              </DialogTitle>
              <DialogDescription className="mt-0.5 text-sm text-muted-foreground">
                Upload CSV or XLSX file to bulk import leads into Viteseo
              </DialogDescription>
            </div>
            <button
              type="button"
              onClick={() => handleOpenChange(false)}
              className="flex h-8 w-8 items-center justify-center rounded-full text-muted-foreground transition-colors hover:bg-muted focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
              aria-label="Close"
            >
              <X className="h-4 w-4" />
            </button>
          </div>
        </DialogHeader>

        {/* Body */}
        <div className="flex-1 overflow-y-auto px-6 py-4">
          {/* gap-4 rather than gap-5: with nine stacked sections the container gap alone was the
              single largest vertical cost in the dialog. */}
          <div className="flex flex-col gap-4">
            {isManager ? (
              <FormField label="Assign to Business Development Manager" required>
                <RelationshipSelect
                  value={assignedTo}
                  onValueChange={setAssignedTo}
                  options={bdUsers.map((u) => ({ value: u.id, label: u.name }))}
                  placeholder={bdLoading ? "Loading…" : "Select a BD to own these leads"}
                  triggerClassName="w-full"
                  aria-label="Assign to Business Development Manager"
                />
              </FormField>
            ) : null}

            {step === "empty" && (
              <UploadDropzone onFile={handleFile} disabled={importLeads.isPending} />
            )}

            {(step === "uploading" || step === "analyzing") && file && (
              <>
                <UploadProgressItem
                  filename={file.name}
                  size={formatBytes(file.size)}
                  percent={progress}
                  // Cancel only while bytes are still in flight; once the server has the file the
                  // request is already being processed and aborting would misrepresent the outcome.
                  onCancel={step === "uploading" ? handleCancelUpload : undefined}
                  status={step === "uploading" ? "Uploading file…" : "Analyzing workbook on the server…"}
                  busy={step === "analyzing"}
                />
                {showStages && <ImportStages stages={stages} />}
              </>
            )}

            {/* Large legacy .xls only: converted on the queue because a workbook this size cannot
                be normalized inside a request. Same visual language as the other phases — no new
                surface, and a failure is shown here rather than silently resetting the dialog. */}
            {step === "converting" && file && (
              <>
                <UploadProgressItem
                  filename={file.name}
                  size={formatBytes(file.size)}
                  percent={100}
                  status={
                    jobFailed
                      ? "Conversion failed"
                      : "Large .xls — converting on the server…"
                  }
                  busy={!jobFailed}
                />
                {jobFailed && (
                  <AlertBanner
                    message={
                      jobStatus.data?.error ??
                      "The Excel file could not be processed. Please re-save it as .xlsx and try again."
                    }
                  />
                )}
              </>
            )}

            {(step === "preview" || step === "importing") && result && file && (
              <>
                <UploadProgressItem
                  filename={file.name}
                  size={formatBytes(file.size)}
                  percent={100}
                  status={step === "importing" ? "Importing records…" : "Analysis complete"}
                  busy={step === "importing"}
                />

                {showStages && <ImportStages stages={stages} />}

                {/* mb-0: the flex container already supplies the gap, so the header's own bottom
                    margin was double-spacing this section. */}
                <SectionHeader title="Import Preview" className="mb-0" />

                <div className="grid grid-cols-2 gap-3 sm:grid-cols-4">
                  {buildStats(result.summary).map((stat) => (
                    // Dialog-sized value: the dashboard default (text-display, 30px) is tuned for a
                    // full-width page and is unnecessarily tall inside a modal. Uses the size prop the
                    // card already exposes — no change to the shared card.
                    // bottomAlignValue: "SKIPPED (DUPLICATE)" wraps to two lines while the others do
                    // not, so the numbers are pinned to the card bottom to share one baseline.
                    <KpiStatCard
                      key={stat.id}
                      stat={stat}
                      valueSizeClassName="text-2xl"
                      bottomAlignValue
                    />
                  ))}
                </div>

                {result.summary.duplicates > 0 && (
                  <AlertBanner
                    message={`We found ${result.summary.duplicates} duplicate lead${result.summary.duplicates === 1 ? "" : "s"} in this file.`}
                  >
                    <p className="text-sm text-yellow-800">
                      {result.summary.duplicates} duplicate row
                      {result.summary.duplicates === 1 ? "" : "s"} will be skipped during import.
                    </p>
                  </AlertBanner>
                )}

                <div className="flex flex-col gap-1.5">
                  <SectionHeader title="Column Mapping" className="mb-0" />
                  {/* Real count from the response, so the section answers "did it find my columns?"
                      without the user having to count rows. */}
                  <p className="text-sm text-muted-foreground">
                    {result.mapping.length} column{result.mapping.length === 1 ? "" : "s"} matched to
                    CRM fields
                  </p>
                </div>
                <MappingTable rows={result.mapping} />

                {result.unknownColumns && result.unknownColumns.length > 0 && (
                  <AlertBanner
                    message={`${result.unknownColumns.length} column${result.unknownColumns.length === 1 ? " was" : "s were"} not recognized and will be ignored.`}
                  >
                    <p className="text-sm text-yellow-800">
                      Unmapped: {result.unknownColumns.join(", ")}
                    </p>
                  </AlertBanner>
                )}
              </>
            )}
          </div>
        </div>

        {/* Footer */}
        <DialogFooter className="mx-0 mb-0 border-t border-border-subtle px-6 py-4">
          <div className="flex w-full items-center justify-end gap-3">
            <Button
              variant="outline"
              onClick={() => handleOpenChange(false)}
            >
              Cancel
            </Button>
            {/* Stays disabled until validation has actually returned (step === "preview"), so the
                count in the label is always a real figure from the response and never a guess. */}
            <Button
              variant="default"
              onClick={handleImport}
              disabled={
                step !== "preview" ||
                validCount === 0 ||
                (isManager && !assignedTo) ||
                importLeads.isPending
              }
            >
              {step === "importing" ? (
                <>
                  <Loader2 className="h-4 w-4 animate-spin" />
                  Importing&hellip;
                </>
              ) : step === "preview" ? (
                `Import ${validCount} Lead${validCount === 1 ? "" : "s"}`
              ) : (
                "Import Leads"
              )}
            </Button>
          </div>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}
