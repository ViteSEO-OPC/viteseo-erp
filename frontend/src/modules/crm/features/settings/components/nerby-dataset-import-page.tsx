"use client";

import { useState, type ComponentType, type ReactNode } from "react";
import { AlertTriangle, CheckCircle2, FileText, Info, Loader2 } from "lucide-react";
import { Card } from "@/modules/crm/components/ui/card";
import { Button } from "@/modules/crm/components/ui/button";
import { Badge } from "@/modules/crm/components/ui/badge";
import { ConfirmDialog } from "@/modules/crm/components/shared/confirm-dialog/confirm-dialog";
import { UploadDropzone } from "@/modules/crm/components/forms/file-upload/upload-dropzone";
import { SELECT } from "@/modules/crm/features/settings/components/form-styles";
import { getApiErrorMessage } from "@/modules/crm/lib/api-error";
import { cn } from "@/modules/crm/lib/utils";
import {
  DATASET_SOURCE,
  datasetUploadError,
  reasonLabel,
  reasonRows,
  useDatasetDryRun,
  useDatasetImportJob,
  useStartDatasetImport,
  type DatasetManifest,
} from "@/modules/crm/features/settings/hooks/use-nerby-dataset-import";

const cardClass = "border border-border bg-card p-6 shadow-card";

/** Same chip surfaces as the Nearby integration card, so a state reads the same across Settings. */
const NOTE_CLASS = {
  ok: "border-teal-600 bg-teal-100 text-teal-800 dark:border-teal-700 dark:bg-teal-950/50 dark:text-teal-300",
  info: "border-border bg-muted/40 text-muted-foreground",
  blocked: "border-rose-300 bg-rose-100 text-rose-800 dark:border-rose-800 dark:bg-rose-950/50 dark:text-rose-300",
} as const;

function formatBytes(bytes: number): string {
  if (bytes < 1024) return `${bytes} B`;
  if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(1)} KB`;

  return `${(bytes / (1024 * 1024)).toFixed(1)} MB`;
}

const number = new Intl.NumberFormat("en-PH");

/**
 * Settings → NERBY → Dataset Import.
 *
 * Lands an EXISTING NERBY dataset (JSONL) in the admin's own organization. No provider is called — the
 * file is the only input — and every rule about what lands is the server importer's, the same engine
 * the operator command uses:
 *
 *   1. choose the file (Google Places only, for now)
 *   2. dry run — the whole file validated, nothing written, the outcome shown line by line
 *   3. explicit confirmation — the same file and the reviewed SHA-256; the write runs on the queue
 *
 * A business the organization already holds (same place_id, archived ones included) is skipped and
 * never changed. Reached only by Admin / Super Admin: `/zerem/settings` is `minRole: crm_admin`, and
 * every endpoint behind this page refuses a BD server-side.
 */
export function NerbyDatasetImportPage() {
  const [file, setFile] = useState<File | null>(null);
  const [review, setReview] = useState<DatasetManifest | null>(null);
  const [jobId, setJobId] = useState<string | null>(null);
  const [confirmOpen, setConfirmOpen] = useState(false);

  const dryRun = useDatasetDryRun();
  const start = useStartDatasetImport();
  const job = useDatasetImportJob(jobId);

  const locked = jobId !== null;
  // Refused here AND by the server: an empty file has no lines, and a dry run of it would otherwise
  // read as "nothing new to import".
  const emptyFile = file !== null && file.size === 0;

  const choose = (next: File) => {
    setFile(next);
    setReview(null);
    dryRun.reset();
  };

  const runDryRun = () => {
    if (!file || emptyFile) return;
    dryRun.mutate(file, { onSuccess: setReview });
  };

  const confirmImport = () => {
    if (!file || !review) return;
    start.mutate(
      { file, sha256: review.file.sha256 },
      {
        onSuccess: (started) => {
          setConfirmOpen(false);
          setJobId(started.job_id);
        },
      },
    );
  };

  const startOver = () => {
    setFile(null);
    setReview(null);
    setJobId(null);
    dryRun.reset();
    start.reset();
  };

  const blocked = review !== null && (review.status !== "ok" || review.counts.rejected > 0);
  const nothingNew = review !== null && !blocked && review.counts.eligible === 0;
  // "Already in the CRM" is only true when every valid line was skipped as a duplicate; lines can
  // also be filtered out (e.g. a record from another source), and that must not read as a duplicate.
  const allExisting = review !== null && review.counts.valid > 0 && review.counts.skipped === review.counts.valid;

  return (
    <div className="flex flex-col gap-6">
      <header>
        <h2 className="text-2xl font-bold text-foreground">Dataset Import</h2>
        <p className="mt-1 text-sm text-muted-foreground">
          Import an existing NERBY dataset into your organization. Nothing is fetched from Google — the
          file you upload is the only input, and businesses already in the CRM are never changed.
        </p>
      </header>

      {/* ── 1. Choose ─────────────────────────────────────────────────────────────── */}
      <Card className={cardClass}>
        <StepTitle step={1} title="Choose the dataset" />

        <div className="mt-4 flex flex-col gap-4">
          {file ? (
            <div className="flex items-center justify-between gap-3 rounded-lg border border-border bg-muted/40 px-4 py-3">
              <div className="flex min-w-0 items-center gap-3">
                <FileText className="size-5 shrink-0 text-muted-foreground" aria-hidden />
                <div className="min-w-0">
                  <p className="truncate text-sm font-medium text-foreground">{file.name}</p>
                  <p className="text-xs text-muted-foreground">{formatBytes(file.size)}</p>
                </div>
              </div>
              <Button variant="outline" size="sm" onClick={startOver} disabled={locked || dryRun.isPending}>
                Change file
              </Button>
            </div>
          ) : (
            <UploadDropzone
              onFile={choose}
              accept=".jsonl,.ndjson"
              title="Drop the dataset file here"
              formats={["JSONL"]}
              ariaLabel="Choose a JSONL dataset file"
            />
          )}

          <div className="max-w-xs">
            <label htmlFor="dataset-source" className="mb-1.5 block text-sm font-medium text-foreground">
              Source
            </label>
            <select id="dataset-source" className={SELECT} value={DATASET_SOURCE} disabled={locked} onChange={() => {}}>
              <option value={DATASET_SOURCE}>Google Places</option>
            </select>
            <p className="mt-1 text-xs text-muted-foreground">
              Every line must be a Google Places record. Other sources are not offered here yet.
            </p>
          </div>

          {emptyFile && (
            <Note tone="blocked" icon={AlertTriangle}>
              This file is empty. Choose the dataset file again.
            </Note>
          )}

          {dryRun.isError && (
            <Note tone="blocked" icon={AlertTriangle}>
              {datasetUploadError(dryRun.error, "The dry run could not be completed.")}
            </Note>
          )}

          <div>
            <Button onClick={runDryRun} disabled={!file || emptyFile || dryRun.isPending || locked}>
              {dryRun.isPending && <Loader2 className="animate-spin" aria-hidden />}
              {dryRun.isPending ? "Checking every line…" : "Run dry run"}
            </Button>
            <p className="mt-1.5 text-xs text-muted-foreground">A dry run writes nothing.</p>
          </div>
        </div>
      </Card>

      {/* ── 2. Review ─────────────────────────────────────────────────────────────── */}
      {review && (
        <Card className={cardClass}>
          <StepTitle step={2} title="Review the dry run" badge={<Badge variant="outline">Dry run · nothing written</Badge>} />
          <ManifestSummary manifest={review} />

          <div className="mt-5 flex flex-col gap-3">
            {review.status !== "ok" ? (
              <Note tone="blocked" icon={AlertTriangle}>{review.error ?? "The dry run did not complete."}</Note>
            ) : review.counts.rejected > 0 ? (
              <Note tone="blocked" icon={AlertTriangle}>
                {number.format(review.counts.rejected)} line(s) were rejected. Import is blocked until the
                file is fixed and checked again.
              </Note>
            ) : nothingNew ? (
              <Note tone="info" icon={Info}>
                {allExisting
                  ? "Nothing new to import — every business in this file is already in the CRM."
                  : "Nothing to import — no line in this file is eligible. See the reasons above."}
              </Note>
            ) : (
              <Note tone="ok" icon={CheckCircle2}>
                Ready to import {number.format(review.counts.eligible)} new lead(s) into{" "}
                {review.org.name ?? "your organization"}. Existing leads are never changed.
              </Note>
            )}

            {!locked && (
              <div>
                <Button onClick={() => setConfirmOpen(true)} disabled={blocked || nothingNew}>
                  Import {number.format(review.counts.eligible)} lead(s)…
                </Button>
              </div>
            )}
          </div>
        </Card>
      )}

      {/* ── 3. Import ─────────────────────────────────────────────────────────────── */}
      {jobId && (
        <Card className={cardClass}>
          <StepTitle step={3} title="Import" />
          <div className="mt-4 flex flex-col gap-4">
            {job.isError ? (
              <Note tone="blocked" icon={AlertTriangle}>
                {getApiErrorMessage(job.error, "The import status could not be read.")}
              </Note>
            ) : !job.data || job.data.status === "queued" || job.data.status === "running" ? (
              <Note tone="info" icon={Loader2} spin>
                {job.data?.status === "running" ? "Importing…" : "Queued…"} You can leave this page — the
                import continues on the server.
              </Note>
            ) : job.data.status === "failed" ? (
              <Note tone="blocked" icon={AlertTriangle}>{job.data.error ?? "The import failed."}</Note>
            ) : job.data.manifest && job.data.manifest.status !== "ok" ? (
              <Note tone="blocked" icon={AlertTriangle}>
                {job.data.manifest.error ?? "The import was refused. Nothing was written."}
              </Note>
            ) : (
              <Note tone="ok" icon={CheckCircle2}>
                Imported {number.format(job.data.manifest?.counts.imported ?? 0)} new lead(s).
              </Note>
            )}

            {job.data?.manifest && <ManifestSummary manifest={job.data.manifest} />}

            {(job.data?.status === "finished" || job.data?.status === "failed") && (
              <div>
                <Button variant="outline" onClick={startOver}>
                  Import another file
                </Button>
              </div>
            )}
          </div>
        </Card>
      )}

      {review && (
        <ConfirmDialog
          open={confirmOpen}
          onOpenChange={setConfirmOpen}
          title={`Import ${number.format(review.counts.eligible)} new lead(s)?`}
          description={`From ${review.file.name} into ${review.org.name ?? "your organization"}. Businesses already in the CRM, including archived ones, are skipped and never changed.`}
          confirmLabel="Import"
          onConfirm={confirmImport}
          loading={start.isPending}
        >
          <div className="flex flex-col gap-2">
            <p className="text-xs text-muted-foreground">
              Only this exact file can be imported. If it has changed since the dry run, the import is
              refused.
            </p>
            <p className="break-all rounded-md bg-muted/60 px-2 py-1.5 font-mono text-[11px] text-foreground">
              SHA-256 {review.file.sha256}
            </p>
            {start.isError && (
              <p className="text-xs text-destructive">
                {datasetUploadError(start.error, "The import could not be started.")}
              </p>
            )}
          </div>
        </ConfirmDialog>
      )}
    </div>
  );
}

function StepTitle({ step, title, badge }: { step: number; title: string; badge?: ReactNode }) {
  return (
    <div className="flex flex-wrap items-center justify-between gap-2">
      <h3 className="text-base font-semibold text-foreground">
        <span className="mr-2 text-muted-foreground">{step}.</span>
        {title}
      </h3>
      {badge}
    </div>
  );
}

function Note({
  tone,
  icon: Icon,
  spin = false,
  children,
}: {
  tone: keyof typeof NOTE_CLASS;
  icon: ComponentType<{ className?: string; "aria-hidden"?: boolean }>;
  spin?: boolean;
  children: ReactNode;
}) {
  return (
    <div className={cn("flex items-start gap-2 rounded-lg border px-3 py-2.5 text-sm", NOTE_CLASS[tone])} role="status">
      <Icon className={cn("mt-0.5 size-4 shrink-0", spin && "animate-spin")} aria-hidden />
      <p>{children}</p>
    </div>
  );
}

/** The figures an admin decides on: what was read, where it lands, and what happens to each line. */
function ManifestSummary({ manifest }: { manifest: DatasetManifest }) {
  const rows = reasonRows(manifest);
  const dropped = Object.entries(manifest.dropped_fields);
  const isWrite = manifest.mode === "write";

  const tiles: { label: string; value: number; emphasis?: boolean }[] = [
    { label: "Lines read", value: manifest.counts.total },
    { label: "Valid", value: manifest.counts.valid },
    { label: "Rejected", value: manifest.counts.rejected },
    { label: "Skipped", value: manifest.counts.skipped },
    isWrite
      ? { label: "Imported", value: manifest.counts.imported, emphasis: true }
      : { label: "Would import", value: manifest.counts.eligible, emphasis: true },
  ];

  return (
    <div className="mt-4 flex flex-col gap-4">
      <dl className="grid gap-x-6 gap-y-2 text-sm sm:grid-cols-2">
        <Fact label="File" value={`${manifest.file.name} · ${formatBytes(manifest.file.bytes)}`} />
        <Fact label="Organization" value={manifest.org.name ?? `#${manifest.org.id}`} />
        <Fact label="Source" value={manifest.sources.map((s) => (s === "google_places" ? "Google Places" : s)).join(", ")} />
        {manifest.requested_by && <Fact label="Requested by" value={manifest.requested_by.name ?? `#${manifest.requested_by.id}`} />}
        <div className="sm:col-span-2">
          <dt className="text-xs text-muted-foreground">SHA-256</dt>
          <dd className="break-all font-mono text-xs text-foreground">{manifest.file.sha256}</dd>
        </div>
      </dl>

      <div className="grid grid-cols-2 gap-3 sm:grid-cols-5">
        {tiles.map((tile) => (
          <div
            key={tile.label}
            className={cn(
              "rounded-lg border border-border px-3 py-2.5",
              tile.emphasis ? "bg-teal-50 dark:bg-teal-950/40" : "bg-muted/40",
            )}
          >
            <p className="text-xs text-muted-foreground">{tile.label}</p>
            <p className="text-lg font-semibold text-foreground tabular-nums">{number.format(tile.value)}</p>
          </div>
        ))}
      </div>

      {rows.length > 0 && (
        <div className="overflow-x-auto rounded-lg border border-border">
          <table className="w-full text-sm">
            <thead className="bg-muted/40 text-left text-xs text-muted-foreground">
              <tr>
                <th className="px-3 py-2 font-medium">Outcome</th>
                <th className="px-3 py-2 font-medium">Reason</th>
                <th className="px-3 py-2 text-right font-medium">Lines</th>
                <th className="px-3 py-2 font-medium">First line numbers</th>
              </tr>
            </thead>
            <tbody>
              {rows.map((row) => (
                <tr key={`${row.bucket}:${row.code}`} className="border-t border-border">
                  <td className="px-3 py-2 capitalize">{row.bucket}</td>
                  <td className="px-3 py-2">{reasonLabel(row.code)}</td>
                  <td className="px-3 py-2 text-right tabular-nums">{number.format(row.count)}</td>
                  <td className="px-3 py-2 text-xs text-muted-foreground">
                    {row.lines.slice(0, 10).join(", ")}
                    {row.count > Math.min(row.lines.length, 10) ? ", …" : ""}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}

      {dropped.length > 0 && (
        <p className="text-xs text-muted-foreground">
          Fields ignored (never copied): {dropped.map(([field, count]) => `${field} (${number.format(count)})`).join(", ")}
        </p>
      )}
    </div>
  );
}

function Fact({ label, value }: { label: string; value: string }) {
  return (
    <div>
      <dt className="text-xs text-muted-foreground">{label}</dt>
      <dd className="text-foreground">{value}</dd>
    </div>
  );
}
