import { apiClient } from "@/core/api-client";
import type {
  ChartOfAccounts,
  GlAccountInput,
  JournalEntry,
  JournalRunStatus,
} from "@/modules/hr/types/accounting";

/** FIN-043 — the chart of accounts, plus which payroll roles still need an account mapped. */
export async function getGlAccounts(): Promise<ChartOfAccounts> {
  const res = await apiClient.get("/hr/gl-accounts");
  return { accounts: res.data.data, ...res.data.meta };
}

export async function createGlAccount(input: GlAccountInput) {
  const res = await apiClient.post("/hr/gl-accounts", input);
  return res.data.data;
}

export async function updateGlAccount(id: number, input: Partial<GlAccountInput>) {
  const res = await apiClient.patch(`/hr/gl-accounts/${id}`, input);
  return res.data.data;
}

export async function deleteGlAccount(id: number): Promise<void> {
  await apiClient.delete(`/hr/gl-accounts/${id}`);
}

/** What is on the books for this run, if anything — null when nothing has been generated. */
export async function getRunJournal(runId: number): Promise<JournalRunStatus> {
  const res = await apiClient.get(`/hr/payroll/runs/${runId}/journal`);
  return { entry: res.data.data, ...res.data.meta };
}

/** Build or rebuild the DRAFT. Refused when the run is already posted — reverse it first. */
export async function buildRunJournal(
  runId: number,
  postingDate?: string,
): Promise<JournalEntry> {
  const res = await apiClient.post(`/hr/payroll/runs/${runId}/journal`, {
    ...(postingDate ? { posting_date: postingDate } : {}),
  });
  return res.data.data;
}

export async function postJournalEntry(id: number): Promise<JournalEntry> {
  const res = await apiClient.post(`/hr/journal-entries/${id}/post`);
  return res.data.data;
}

/** Cancels with a mirror entry rather than deleting — a posted journal is history. */
export async function reverseJournalEntry(id: number, reason: string): Promise<JournalEntry> {
  const res = await apiClient.post(`/hr/journal-entries/${id}/reverse`, { reason });
  return res.data.data;
}

/** The two CSV downloads. Blob rather than JSON — these are files, not payloads. */
async function download(url: string, params: Record<string, string>, filename: string) {
  const res = await apiClient.get(url, { params, responseType: "blob" });
  const href = URL.createObjectURL(res.data as Blob);
  const link = document.createElement("a");
  link.href = href;
  link.download = filename;
  link.click();
  URL.revokeObjectURL(href);
}

export function exportJournal(from: string, to: string) {
  return download("/hr/accounting/export", { from, to }, `payroll-journal-${from}-to-${to}.csv`);
}

export function exportRemittance(month: string, agency: string) {
  return download(
    "/hr/accounting/remittance",
    { month, agency },
    `${agency}-remittance-${month}.csv`,
  );
}
