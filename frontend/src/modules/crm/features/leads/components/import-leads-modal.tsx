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
import { X } from "lucide-react";
import { SectionHeader } from "@/modules/crm/components/shared/section-header/section-header";
import { KpiStatCard } from "@/modules/crm/features/dashboard/components/kpi-stat-card";
import { AlertBanner } from "@/modules/crm/components/shared/alert-banner/alert-banner";
import { MappingTable } from "@/modules/crm/features/leads/components/mapping-table";
import { UploadDropzone } from "@/modules/crm/components/forms/file-upload/upload-dropzone";
import { UploadProgressItem } from "@/modules/crm/components/forms/file-upload/upload-progress-item";
import { FormField } from "@/modules/crm/components/forms/form-field/form-field";
import { RelationshipSelect } from "@/modules/crm/components/shared/relationship-select/relationship-select";
import { useBdUsers } from "@/modules/crm/hooks/use-bd-users";
import { usePermissions } from "@/modules/crm/providers/permissions-provider";
import { useImportLeads } from "@/modules/crm/features/leads/hooks/use-lead-mutations";
import type { KpiStat } from "@/modules/crm/features/dashboard/types/dashboard-types";
import type { ImportResult, ImportSummary } from "@/modules/crm/features/leads/types/lead-types";

const FLAT_DELTA = { direction: "flat", text: "", tone: "neutral" } as const;

function buildStats(summary: ImportSummary): KpiStat[] {
  return [
    { id: "total", label: "TOTAL ROWS", value: String(summary.total), valueVariant: "neutral", delta: FLAT_DELTA },
    { id: "valid", label: "VALID", value: String(summary.valid), valueVariant: "accent", delta: FLAT_DELTA },
    { id: "duplicates", label: "DUPLICATES", value: String(summary.duplicates), valueVariant: "neutral", delta: FLAT_DELTA },
    { id: "errors", label: "ERRORS", value: String(summary.errors), valueVariant: "neutral", delta: FLAT_DELTA },
  ];
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
  const [step, setStep] = useState<"empty" | "uploading" | "preview">("empty");
  const [progress, setProgress] = useState(0);
  const [file, setFile] = useState<File | null>(null);
  const [result, setResult] = useState<ImportResult | null>(null);
  // Owning BD for the imported leads. Admin/SA pick it; a BD's own import auto-assigns to
  // themselves (backend), so the selector is hidden for BDs.
  const [assignedTo, setAssignedTo] = useState<string>("");

  const { hasMinRole } = usePermissions();
  const isManager = hasMinRole("crm_admin");
  const importLeads = useImportLeads();
  // Only Admin/SA need the BD list (BDs lack users.view — avoid a 403).
  const { bdUsers, isLoading: bdLoading } = useBdUsers(isManager);
  const abortRef = useRef<AbortController | null>(null);

  const resetState = () => {
    abortRef.current?.abort();
    abortRef.current = null;
    setStep("empty");
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
    setStep("uploading");
    const controller = new AbortController();
    abortRef.current = controller;
    try {
      const res = await importLeads.mutateAsync({
        file: selected,
        dryRun: true,
        assignedTo: assignedTo || undefined,
        onUploadProgress: setProgress,
        signal: controller.signal,
      });
      setResult(res);
      setStep("preview");
    } catch {
      // Cancelled or failed (error toast handled in the hook). Only reset if this
      // upload is still the active one (a cancel already resets and clears the ref).
      if (abortRef.current === controller) {
        setStep("empty");
        setFile(null);
      }
    } finally {
      if (abortRef.current === controller) abortRef.current = null;
    }
  };

  const handleCancelUpload = () => {
    abortRef.current?.abort();
    abortRef.current = null;
    setStep("empty");
    setProgress(0);
    setFile(null);
  };

  const handleImport = async () => {
    if (!file) return;
    try {
      await importLeads.mutateAsync({ file, dryRun: false, assignedTo: assignedTo || undefined });
      handleOpenChange(false); // success toast handled in the hook
    } catch {
      // Commit failed; toast shown by the hook. Stay on preview for retry.
    }
  };

  const validCount = result?.summary.valid ?? 0;

  return (
    <Dialog open={open} onOpenChange={handleOpenChange}>
      <DialogContent
        showCloseButton={false}
        className="flex max-h-[90vh] w-full flex-col gap-0 p-0 sm:max-w-[520px]"
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
          <div className="flex flex-col gap-5">
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

            {step === "uploading" && file && (
              <UploadProgressItem
                filename={file.name}
                size={formatBytes(file.size)}
                percent={progress}
                onCancel={handleCancelUpload}
              />
            )}

            {step === "preview" && result && file && (
              <>
                <UploadProgressItem
                  filename={file.name}
                  size={formatBytes(file.size)}
                  percent={100}
                />

                <SectionHeader title="Import Preview" />

                <div className="grid grid-cols-2 gap-3 sm:grid-cols-4">
                  {buildStats(result.summary).map((stat) => (
                    <KpiStatCard key={stat.id} stat={stat} />
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

                <MappingTable rows={result.mapping} />

                {result.unknownColumns && result.unknownColumns.length > 0 && (
                  <AlertBanner
                    message={`${result.unknownColumns.length} column${result.unknownColumns.length === 1 ? "" : "s"} were not recognized and will be ignored.`}
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
            <Button
              variant="default"
              onClick={handleImport}
              disabled={step !== "preview" || validCount === 0 || (isManager && !assignedTo) || importLeads.isPending}
            >
              Import {validCount} Leads
            </Button>
          </div>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}
