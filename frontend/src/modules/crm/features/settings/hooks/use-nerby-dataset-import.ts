"use client";

import { useEffect } from "react";
import { isAxiosError } from "axios";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { apiClient } from "@/modules/crm/lib/axios";
import { getApiErrorMessage } from "@/modules/crm/lib/api-error";
import { queryKeys } from "@/modules/crm/lib/query-keys";

/**
 * Settings → NERBY → Dataset Import.
 *
 * A thin client for the server's NERBY dataset importer — the same engine as the operator command.
 * The browser never decides what lands: it uploads a file, shows the server's dry-run manifest, and
 * sends the explicit confirmation back.
 *
 * TWO UPLOADS OF THE SAME FILE, BY DESIGN. The dry run returns the file's SHA-256; the import sends the
 * same file with that hash, and the server refuses any bytes that do not match. So a confirmation can
 * only ever import the file that was reviewed, without the server keeping uploads between the steps.
 *
 * The org is never named: the server imports into the signed-in admin's own organization.
 */

export const DATASET_IMPORT_ENDPOINT = "/settings/nerby/dataset-import";

/** The only source this screen offers for now (server-enforced too). */
export const DATASET_SOURCE = "google_places" as const;

export type DatasetReason = { count: number; lines: number[] };

/** PHP encodes an empty map as `[]`, so a bucket with no reasons may arrive as an empty array. */
type ReasonBucket = Record<string, DatasetReason> | [];

export type DatasetManifest = {
  mode: "dry_run" | "write";
  /** `ok` = ran; `refused` = nothing written (e.g. the file changed after review); `failed` = stopped. */
  status: "ok" | "refused" | "failed";
  error: string | null;
  file: { name: string; sha256: string; bytes: number; lines: number };
  org: { id: number; name: string | null };
  sources: string[];
  counts: {
    total: number;
    valid: number;
    rejected: number;
    filtered: number;
    skipped: number;
    /** Dry run: would import. Write: what was handed to the ingest. */
    eligible: number;
    imported: number;
  };
  reasons: { rejected: ReasonBucket; filtered: ReasonBucket; skipped: ReasonBucket };
  dropped_fields: Record<string, number>;
  requested_by: { id: number; name: string | null } | null;
  started_at: string;
  finished_at: string | null;
};

export type DatasetImportJob = {
  job_id: string;
  /** `finished` means the job is over — the manifest's own `status` says whether it imported. */
  status: "queued" | "running" | "finished" | "failed";
  error: string | null;
  file_name: string;
  sha256: string;
  created_at: string;
  manifest: DatasetManifest | null;
};

/**
 * An upload failure in words an admin can act on. A 413 is PHP's `post_max_size` refusing the whole
 * request before Laravel sees it ("The POST data is too large."); a file over `upload_max_filesize`
 * comes back as a 422 whose message the server already words, with the limit.
 */
export function datasetUploadError(err: unknown, fallback: string): string {
  if (isAxiosError(err) && err.response?.status === 413) {
    return TOO_LARGE;
  }
  if (err instanceof UnexpectedResponseError) return err.message;

  return getApiErrorMessage(err, fallback);
}

const TOO_LARGE =
  "The file is larger than this server accepts. The server's upload limit must be raised before this dataset can be imported.";

/**
 * A 2xx that is not our `{ data: … }` envelope. Seen when PHP refuses an over-`post_max_size` body
 * and, with `display_errors` on, prints its warning as a 200 HTML page before Laravel can answer 413.
 * Treated as a failure so the screen reports it instead of reading fields off `undefined`.
 */
class UnexpectedResponseError extends Error {
  constructor() {
    super(
      "The server returned an unexpected response. If the file is large, it may exceed the server's upload limit, which must be raised before this dataset can be imported.",
    );
  }
}

/** The envelope's `data`, or an {@link UnexpectedResponseError} when the body is not one. */
function envelope<T>(body: unknown, key: keyof T & string): T {
  const data = (body as { data?: unknown } | null)?.data;
  if (data === null || typeof data !== "object" || !(key in data)) throw new UnexpectedResponseError();

  return data as T;
}

function upload(file: File, extra: Record<string, string> = {}): FormData {
  const form = new FormData();
  form.append("file", file);
  form.append("source", DATASET_SOURCE);
  for (const [key, value] of Object.entries(extra)) form.append(key, value);

  return form;
}

/** Validate the whole file and report what would happen. Writes no lead. */
export function useDatasetDryRun() {
  return useMutation({
    mutationFn: async (file: File) => {
      const res = await apiClient.post(`${DATASET_IMPORT_ENDPOINT}/dry-run`, upload(file), {
        headers: { "Content-Type": "multipart/form-data" },
      });
      return envelope<DatasetManifest>(res.data, "counts");
    },
  });
}

/** The explicit confirmation: same file + the reviewed SHA-256. Queues the write; answers with a job. */
export function useStartDatasetImport() {
  return useMutation({
    mutationFn: async ({ file, sha256 }: { file: File; sha256: string }) => {
      const res = await apiClient.post(DATASET_IMPORT_ENDPOINT, upload(file, { confirm_sha256: sha256 }), {
        headers: { "Content-Type": "multipart/form-data" },
      });
      return envelope<DatasetImportJob>(res.data, "job_id");
    },
  });
}

/**
 * Poll a queued import until it is over. When it finishes having written leads, the lead lists and
 * the Nearby pool are refreshed — the only moment this screen changes data anyone else is looking at.
 */
export function useDatasetImportJob(jobId: string | null) {
  const queryClient = useQueryClient();
  const query = useQuery({
    queryKey: queryKeys.nerbyDatasetImport.job(jobId ?? ""),
    enabled: jobId !== null,
    refetchInterval: (q) => {
      const status = q.state.data?.status;
      return status === "finished" || status === "failed" ? false : 2000;
    },
    queryFn: async () => {
      const res = await apiClient.get(`${DATASET_IMPORT_ENDPOINT}/${jobId}`);
      return envelope<DatasetImportJob>(res.data, "job_id");
    },
  });

  const imported = query.data?.status === "finished" ? (query.data.manifest?.counts.imported ?? 0) : 0;
  useEffect(() => {
    if (imported > 0) {
      void queryClient.invalidateQueries({ queryKey: queryKeys.leads.all });
      void queryClient.invalidateQueries({ queryKey: queryKeys.nearbyClients.all });
    }
  }, [imported, queryClient]);

  return query;
}

/** Plain-language labels for the importer's reason codes. Unknown codes fall back to the code. */
const REASON_LABELS: Record<string, string> = {
  blank_line: "Blank line",
  invalid_utf8: "Not valid UTF-8",
  invalid_json: "Not valid JSON",
  not_an_object: "Line is not a JSON object",
  place_id_source_mismatch: "place_id does not match its source",
  outside_philippines: "Coordinates outside the Philippines",
  source_not_selected: "Source not selected",
  ai_flag_not_selected: "AI flag not selected",
  existing_place_id: "Already in the CRM (same place_id)",
  existing_place_id_soft_deleted: "Already in the CRM, archived (same place_id)",
  existing_place_id_at_write: "Added by another process before this import",
  duplicate_place_id_in_file: "Repeated place_id within the file",
  existing_email: "Already in the CRM (same email)",
  duplicate_email_in_file: "Repeated email within the file",
};

export function reasonLabel(code: string): string {
  if (code.startsWith("invalid_field:")) return `Invalid field: ${code.slice("invalid_field:".length)}`;

  return REASON_LABELS[code] ?? code;
}

/** Flatten a manifest's reasons into display rows, largest first. */
export function reasonRows(manifest: DatasetManifest) {
  const rows: { bucket: "rejected" | "filtered" | "skipped"; code: string; count: number; lines: number[] }[] = [];
  for (const bucket of ["rejected", "filtered", "skipped"] as const) {
    for (const [code, reason] of Object.entries(manifest.reasons[bucket])) {
      rows.push({ bucket, code, count: reason.count, lines: reason.lines });
    }
  }

  return rows.sort((a, b) => b.count - a.count);
}
