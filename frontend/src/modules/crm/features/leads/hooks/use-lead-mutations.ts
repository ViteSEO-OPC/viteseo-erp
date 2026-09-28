"use client";

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { isCancel } from "axios";
import { notify as toast } from "@/core/lib/notify";
import { apiClient } from "@/modules/crm/lib/axios";
import { getApiErrorMessage } from "@/modules/crm/lib/api-error";
import { queryKeys } from "@/modules/crm/lib/query-keys";
import { EDITABLE_STATUS_OPTIONS } from "@/modules/crm/lib/lead-status";
import { assignedToPayload, isUnassignedBd } from "@/modules/crm/lib/bd-assignment";
import type {
  ImportResult,
  Lead,
  LeadWritePayload,
} from "@/modules/crm/features/leads/types/lead-types";

/** Create a lead. Omitted fields fall through to backend/DB defaults. */
export function useCreateLead() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (payload: LeadWritePayload) =>
      apiClient.post("/leads", payload).then((r) => r.data.data as Lead),
    onSuccess: (lead) => {
      queryClient.invalidateQueries({ queryKey: queryKeys.leads.lists() });
      // A new lead moves dashboard counts (Total / Leads-by-Status / Lead Acquisition) and
      // My Performance (assigned / Estimated Pipeline), and logs a `created` Activity.
      queryClient.invalidateQueries({ queryKey: queryKeys.dashboard.all });
      queryClient.invalidateQueries({ queryKey: queryKeys.performance.all });
      queryClient.invalidateQueries({ queryKey: queryKeys.activityLogs.all });
      toast.success(`Lead "${lead.business}" created`);
    },
    onError: (err) => {
      toast.error(getApiErrorMessage(err, "Failed to create lead"));
    },
  });
}

/** Update a lead (partial). Invalidates the list and the lead's detail. */
export function useUpdateLead() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: ({ id, payload }: { id: string; payload: LeadWritePayload }) =>
      apiClient.patch(`/leads/${id}`, payload).then((r) => r.data.data as Lead),
    onSuccess: (lead, { id }) => {
      queryClient.invalidateQueries({ queryKey: queryKeys.leads.lists() });
      queryClient.invalidateQueries({ queryKey: queryKeys.leads.detail(id) });
      // An edit / status quick-change can move the Lead Score, Estimated Pipeline (leads.amount),
      // and the status / priority aggregates — refresh every computed view. The `updated` Activity
      // surfaces in the feeds; the per-lead timeline is covered by detail(id) (prefix match).
      queryClient.invalidateQueries({ queryKey: queryKeys.dashboard.all });
      queryClient.invalidateQueries({ queryKey: queryKeys.performance.all });
      queryClient.invalidateQueries({ queryKey: queryKeys.activityLogs.all });
      // Setting Cold Calling → Scheduled makes the LeadObserver auto-create a follow-up PlannerEvent
      // (via the single FollowUpScheduler); lead-name edits also flow onto existing event cards.
      // Refresh the one shared Planner cache — the same key the Outreach inbox reads — so a newly
      // created follow-up appears without a page refresh.
      queryClient.invalidateQueries({ queryKey: queryKeys.planner.list() });
      toast.success(`Lead "${lead.business}" updated`);
    },
    onError: (err) => {
      toast.error(getApiErrorMessage(err, "Failed to save changes"));
    },
  });
}

/** Assign / reassign a lead to a BD (Admin/SA only). Logs a `lead.assigned` Activity. */
export function useAssignLead() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: ({ id, assignedTo }: { id: string; assignedTo: string }) =>
      apiClient
        // null clears the owner; the endpoint requires the key to be PRESENT either way, so this
        // always sends it rather than omitting the field for the unassigned case.
        .patch(`/leads/${id}/assign`, { assigned_to: assignedToPayload(assignedTo) })
        .then((r) => r.data.data as Lead),
    onSuccess: (lead) => {
      queryClient.invalidateQueries({ queryKey: queryKeys.leads.lists() });
      queryClient.invalidateQueries({ queryKey: queryKeys.leads.detail(lead.id) });
      // Reassignment changes the per-BD "assigned" counts (BD Performance) and the ownership-scoped
      // My Performance / dashboard aggregates; the lead.assigned Activity surfaces in the feeds.
      // dashboard.all supersedes the prior recentActivity()-only invalidation.
      queryClient.invalidateQueries({ queryKey: queryKeys.dashboard.all });
      queryClient.invalidateQueries({ queryKey: queryKeys.performance.all });
      queryClient.invalidateQueries({ queryKey: queryKeys.activityLogs.all });
      toast.success(`Assigned to ${lead.assignedTo?.name ?? "BD"}`);
    },
    onError: (err) => {
      toast.error(getApiErrorMessage(err, "Failed to assign lead"));
    },
  });
}

export type ImportLeadsVars = {
  /** Omitted when committing an already-converted large .xls — `jobId` identifies it instead. */
  file?: File;
  /**
   * Job id returned by a queued .xls conversion (HTTP 202). Sending this instead of the file means
   * a 10 MB workbook is converted ONCE: the server reuses the converted copy it already holds.
   */
  jobId?: string;
  /** true = Preview (no writes); false = commit the import. */
  dryRun: boolean;
  /** Owning BD for the imported leads (Admin/SA select in the wizard). */
  assignedTo?: string;
  onUploadProgress?: (percent: number) => void;
  signal?: AbortSignal;
};

/**
 * Upload a CSV/XLSX file to POST /leads/import. Used twice per the two-step flow:
 * dryRun=true for the preview, dryRun=false to commit. Only a committed import
 * mutates data, so the list is invalidated and a toast shown only then.
 */
export function useImportLeads() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: async ({ file, jobId, dryRun, assignedTo, onUploadProgress, signal }: ImportLeadsVars) => {
      const form = new FormData();
      if (file) form.append("file", file);
      if (jobId) form.append("job_id", jobId);
      form.append("dry_run", dryRun ? "true" : "false");
      if (assignedTo) form.append("assigned_to", assignedTo);
      const res = await apiClient.post("/leads/import", form, {
        headers: { "Content-Type": "multipart/form-data" },
        signal,
        onUploadProgress: (e) => {
          if (onUploadProgress && e.total) {
            onUploadProgress(Math.round((e.loaded / e.total) * 100));
          }
        },
      });
      // 202 = a large .xls was routed to the queue; the caller polls until the preview is ready.
      // Every other response is the unchanged synchronous ImportResult.
      if (res.status === 202) {
        return { queued: true as const, jobId: res.data.data.jobId as string };
      }
      return res.data.data as ImportResult;
    },
    onSuccess: (result) => {
      // A queued conversion has written nothing yet — nothing to invalidate, nothing to announce.
      if ("queued" in result) return;
      if (!result.dryRun) {
        queryClient.invalidateQueries({ queryKey: queryKeys.leads.lists() });
        // A committed import adds many leads → dashboard counts + Lead Acquisition, My Performance
        // (assigned / Estimated Pipeline), and the activity feeds all change.
        queryClient.invalidateQueries({ queryKey: queryKeys.dashboard.all });
        queryClient.invalidateQueries({ queryKey: queryKeys.performance.all });
        queryClient.invalidateQueries({ queryKey: queryKeys.activityLogs.all });
        toast.success(
          `Imported ${result.importedCount} lead${result.importedCount === 1 ? "" : "s"}`,
        );
      }
    },
    onError: (err) => {
      if (isCancel(err)) return; // user cancelled the upload — not an error
      toast.error(getApiErrorMessage(err, "Failed to import leads"));
    },
  });
}

/** Soft-delete a lead. Invalidates the list. */
export function useDeleteLead() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (id: string) => apiClient.delete(`/leads/${id}`),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: queryKeys.leads.lists() });
      // A delete reduces Total Leads, status / priority aggregates, BD Performance, Lead Acquisition,
      // and My Performance (incl. Estimated Pipeline / Revenue when the lead carried an amount / deal).
      queryClient.invalidateQueries({ queryKey: queryKeys.dashboard.all });
      queryClient.invalidateQueries({ queryKey: queryKeys.performance.all });
      queryClient.invalidateQueries({ queryKey: queryKeys.activityLogs.all });
      toast.success("Lead deleted");
    },
    onError: (err) => {
      toast.error(getApiErrorMessage(err, "Failed to delete lead"));
    },
  });
}

/** Outcome of a bulk delete: which ids succeeded and which failed (with a reason). */
export type BulkDeleteResult = {
  succeeded: string[];
  failed: { id: string; reason: unknown }[];
};

/**
 * Bulk soft-delete leads. There is no bulk endpoint, so this fans out over the SAME
 * authoritative `DELETE /leads/{id}` route once per lead — identical behavior to
 * {@link useDeleteLead} (observers, activity logging and soft-deletes all intact); no new
 * delete logic is introduced. `Promise.allSettled` lets a single failure not abort the rest:
 * the list is invalidated whenever anything was deleted, and the caller can keep the failed
 * ids selected. One combined success/error toast (reusing the shared helpers) replaces the
 * per-lead toast to avoid a flood.
 */
export function useBulkDeleteLeads() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: async (ids: string[]): Promise<BulkDeleteResult> => {
      const outcomes = await Promise.all(
        ids.map(async (id) => {
          try {
            await apiClient.delete(`/leads/${id}`);
            return { id, ok: true as const };
          } catch (reason) {
            return { id, ok: false as const, reason };
          }
        }),
      );
      const succeeded = outcomes.filter((o) => o.ok).map((o) => o.id);
      const failed = outcomes
        .filter((o) => !o.ok)
        .map((o) => ({ id: o.id, reason: "reason" in o ? o.reason : undefined }));
      return { succeeded, failed };
    },
    onSuccess: ({ succeeded, failed }) => {
      if (succeeded.length > 0) {
        queryClient.invalidateQueries({ queryKey: queryKeys.leads.lists() });
        // Same computed views as the single delete — refresh dashboards, My Performance, and feeds.
        queryClient.invalidateQueries({ queryKey: queryKeys.dashboard.all });
        queryClient.invalidateQueries({ queryKey: queryKeys.performance.all });
        queryClient.invalidateQueries({ queryKey: queryKeys.activityLogs.all });
        toast.success(
          `${succeeded.length} lead${succeeded.length === 1 ? "" : "s"} deleted`,
        );
      }
      if (failed.length > 0) {
        toast.error(
          getApiErrorMessage(
            failed[0]?.reason,
            `Failed to delete ${failed.length} lead${failed.length === 1 ? "" : "s"}`,
          ),
        );
      }
    },
    onError: (err) => {
      toast.error(getApiErrorMessage(err, "Failed to delete leads"));
    },
  });
}

/** Outcome of a bulk assignment (niche or owning BD): which ids took the new value and which failed. */
export type BulkAssignResult = {
  succeeded: string[];
  failed: { id: string; reason: unknown }[];
};

/**
 * Assign one or more leads to a niche — a single logical action that fans out over the SAME
 * authoritative `PATCH /leads/{id}` route (once per lead) with `{ industry }`. Assigning a niche is
 * canonically just setting `lead.industry` to the niche NAME (there is no niche_id / relationship),
 * so this introduces no new backend or business logic — it reuses the update endpoint {@link
 * useUpdateLead} already calls.
 *
 * `Promise.all` + per-request try/catch lets one failure NOT abort the rest; the caller keeps the
 * failed ids selected. Because a reassignment rewrites `lead.industry`, EVERY surface that reads a
 * lead's niche is refreshed on completion — the Leads lists + chips/dots, the niche registry counts
 * (crm_niches.leadCount), the "Leads by niche" report, the Dashboard, My Performance and the feeds.
 * One combined success / error toast replaces per-lead toasts.
 */
export function useAssignLeadsToNiche() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: async ({ ids, niche }: { ids: string[]; niche: string }): Promise<BulkAssignResult> => {
      const outcomes = await Promise.all(
        ids.map(async (id) => {
          try {
            await apiClient.patch(`/leads/${id}`, { industry: niche });
            return { id, ok: true as const };
          } catch (reason) {
            return { id, ok: false as const, reason };
          }
        }),
      );
      const succeeded = outcomes.filter((o) => o.ok).map((o) => o.id);
      const failed = outcomes
        .filter((o) => !o.ok)
        .map((o) => ({ id: o.id, reason: "reason" in o ? o.reason : undefined }));
      return { succeeded, failed };
    },
    onSuccess: ({ succeeded, failed }, { niche }) => {
      if (succeeded.length > 0) {
        queryClient.invalidateQueries({ queryKey: queryKeys.leads.lists() });
        queryClient.invalidateQueries({ queryKey: queryKeys.niches.all });
        queryClient.invalidateQueries({ queryKey: queryKeys.reports.all });
        queryClient.invalidateQueries({ queryKey: queryKeys.dashboard.all });
        queryClient.invalidateQueries({ queryKey: queryKeys.performance.all });
        queryClient.invalidateQueries({ queryKey: queryKeys.activityLogs.all });
        toast.success(
          `${succeeded.length} lead${succeeded.length === 1 ? "" : "s"} assigned to "${niche}"`,
        );
      }
      if (failed.length > 0) {
        toast.error(
          getApiErrorMessage(
            failed[0]?.reason,
            `Failed to assign ${failed.length} lead${failed.length === 1 ? "" : "s"}`,
          ),
        );
      }
    },
    onError: (err) => {
      toast.error(getApiErrorMessage(err, "Failed to assign leads"));
    },
  });
}

/**
 * Assign one or more leads to a single BD — one logical action fanned out over the SAME
 * authoritative `PATCH /leads/{id}/assign` route {@link useAssignLead} already uses (once per lead).
 * No new backend endpoint or assignment logic is introduced: every lead goes through the identical
 * `leads.assign` policy check (Admin/SA), the MustBeBusinessDeveloper validation, and the dedicated
 * `lead.assigned` Activity.
 *
 * Per-request try/catch lets one failure NOT abort the rest, and the caller keeps the failed ids
 * selected — a partial failure leaves the selection accurate rather than corrupted. Reassignment
 * moves the per-BD "assigned" counts and every ownership-scoped aggregate, so this refreshes exactly
 * the caches the single assign does, plus each reassigned lead's own detail. One combined success /
 * error toast replaces the per-lead toasts.
 */
/**
 * Set ONE status on every selected lead — a single logical action that fans out over the SAME
 * authoritative `PATCH /leads/{id}` route (once per lead) with `{ status }`. This is exactly what
 * the Lead Detail quick-change already sends, so it introduces no new endpoint and no new business
 * logic; the options come from the frozen taxonomy (`EDITABLE_STATUS_OPTIONS`).
 *
 * The per-lead fan-out is deliberate, not a missing bulk endpoint: `LeadObserver::updated` treats
 * `status` as a SCORING_INPUT, so each PATCH re-runs `LeadScorer::refresh` and writes its own
 * `updated` Activity. Doing this N times is the POINT — a bulk change must leave exactly the same
 * scores and audit trail as changing the leads one at a time. Nothing is bypassed or batched away.
 *
 * `Promise.all` + per-request try/catch lets one failure NOT abort the rest; the caller keeps the
 * failed ids selected. Authorization is unchanged and enforced per lead: `LeadPolicy@update` is
 * `crm.leads.edit` + `isVisibleTo`, so a BD can only ever write their own leads even if an id were
 * forged. Status feeds the funnel, the status mix and the dashboard counts, so those caches are
 * refreshed alongside the list on completion.
 */
export function useBulkUpdateLeadStatus() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: async ({ ids, status }: { ids: string[]; status: string }): Promise<BulkAssignResult> => {
      const outcomes = await Promise.all(
        ids.map(async (id) => {
          try {
            await apiClient.patch(`/leads/${id}`, { status });
            return { id, ok: true as const };
          } catch (reason) {
            return { id, ok: false as const, reason };
          }
        }),
      );
      const succeeded = outcomes.filter((o) => o.ok).map((o) => o.id);
      const failed = outcomes
        .filter((o) => !o.ok)
        .map((o) => ({ id: o.id, reason: "reason" in o ? o.reason : undefined }));
      return { succeeded, failed };
    },
    onSuccess: ({ succeeded, failed }, { status }) => {
      if (succeeded.length > 0) {
        queryClient.invalidateQueries({ queryKey: queryKeys.leads.lists() });
        queryClient.invalidateQueries({ queryKey: queryKeys.reports.all });
        queryClient.invalidateQueries({ queryKey: queryKeys.dashboard.all });
        queryClient.invalidateQueries({ queryKey: queryKeys.performance.all });
        queryClient.invalidateQueries({ queryKey: queryKeys.activityLogs.all });
        const label =
          EDITABLE_STATUS_OPTIONS.find((option) => option.value === status)?.label ?? status;
        toast.success(
          `${succeeded.length} lead${succeeded.length === 1 ? "" : "s"} set to "${label}"`,
        );
      }
      if (failed.length > 0) {
        toast.error(
          getApiErrorMessage(
            failed[0]?.reason,
            `Failed to update ${failed.length} lead${failed.length === 1 ? "" : "s"}`,
          ),
        );
      }
    },
    onError: (err) => {
      toast.error(getApiErrorMessage(err, "Failed to update leads"));
    },
  });
}

export function useBulkAssignLeads() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: async ({
      ids,
      assignedTo,
    }: {
      ids: string[];
      /** A BD id, or the UNASSIGNED_BD sentinel to clear the owner. */
      assignedTo: string;
      /** Chosen owner's display name — used only for the success toast. */
      bdName: string;
    }): Promise<BulkAssignResult> => {
      // Resolved ONCE, outside the fan-out: `Number("unassigned")` is NaN, which would serialize as
      // null by accident rather than by intent. assignedToPayload says null deliberately, and the
      // endpoint requires the key to be present for exactly that reason.
      const assigned_to = assignedToPayload(assignedTo);
      const outcomes = await Promise.all(
        ids.map(async (id) => {
          try {
            await apiClient.patch(`/leads/${id}/assign`, { assigned_to });
            return { id, ok: true as const };
          } catch (reason) {
            return { id, ok: false as const, reason };
          }
        }),
      );
      const succeeded = outcomes.filter((o) => o.ok).map((o) => o.id);
      const failed = outcomes
        .filter((o) => !o.ok)
        .map((o) => ({ id: o.id, reason: "reason" in o ? o.reason : undefined }));
      return { succeeded, failed };
    },
    onSuccess: ({ succeeded, failed }, { bdName, assignedTo }) => {
      if (succeeded.length > 0) {
        queryClient.invalidateQueries({ queryKey: queryKeys.leads.lists() });
        // Each reassigned lead's detail (and, by prefix, its activity timeline) now shows a new owner.
        succeeded.forEach((id) => {
          queryClient.invalidateQueries({ queryKey: queryKeys.leads.detail(id) });
        });
        queryClient.invalidateQueries({ queryKey: queryKeys.dashboard.all });
        queryClient.invalidateQueries({ queryKey: queryKeys.performance.all });
        queryClient.invalidateQueries({ queryKey: queryKeys.activityLogs.all });
        // "assigned to Unassigned" would be nonsense, so clearing gets its own sentence.
        toast.success(
          isUnassignedBd(assignedTo)
            ? `${succeeded.length} lead${succeeded.length === 1 ? "" : "s"} are now unassigned`
            : `${succeeded.length} lead${succeeded.length === 1 ? "" : "s"} successfully assigned to ${bdName}`,
        );
      }
      if (failed.length > 0) {
        // Surfaces the backend's own message (incl. the 422 validation summary) for the first failure.
        toast.error(
          getApiErrorMessage(
            failed[0]?.reason,
            `Failed to assign ${failed.length} lead${failed.length === 1 ? "" : "s"}`,
          ),
        );
      }
    },
    onError: (err) => {
      toast.error(getApiErrorMessage(err, "Failed to assign leads"));
    },
  });
}

/** Status of a queued .xls conversion. Mirrors LeadImportStatusController's payload. */
export type ImportJobStatus = {
  jobId: string;
  status: "pending" | "ready" | "failed" | "committed";
  originalName: string;
  result: ImportResult | null;
  error: string | null;
};

/**
 * Poll a queued .xls conversion until it is ready or fails.
 *
 * Enabled only while a job id exists, and it stops polling on a terminal status — so the ordinary
 * synchronous import (every CSV, TXT, XLSX and small .xls) never issues a single extra request.
 */
export function useImportJobStatus(jobId: string | null) {
  return useQuery({
    queryKey: ["leads", "import-job", jobId],
    enabled: Boolean(jobId),
    refetchInterval: (query) => {
      const s = query.state.data?.status;
      return s === "ready" || s === "failed" ? false : 2000;
    },
    queryFn: async () => {
      const res = await apiClient.get(`/leads/import/${jobId}/status`);
      return res.data.data as ImportJobStatus;
    },
  });
}

/**
 * How many leads "Archive all leads" would affect, for the confirmation dialog.
 *
 * Read from the SERVER rather than from the table's `meta.total`, which reflects the current
 * filters and would understate — or overstate — an action that deliberately ignores them. The
 * endpoint shares its scope with the action itself, so the number shown and the number archived
 * cannot disagree.
 *
 * `enabled` keeps it from firing until the dialog is actually open: this is a count for a decision,
 * not something the page needs on load.
 */
export function useDeletableLeadCount(enabled: boolean) {
  const query = useQuery({
    queryKey: queryKeys.leads.deletableCount(),
    queryFn: () =>
      apiClient.get("/leads/deletable-count").then((r) => r.data.data.count as number),
    enabled,
    // The dialog should reflect the pipeline as it is right now, not a cached figure from the last
    // time it was opened.
    staleTime: 0,
  });

  return { count: query.data, isLoading: query.isLoading, isError: query.isError };
}

/**
 * Archive EVERY lead in the caller's authorized scope.
 *
 * One request, not a fan-out: unlike {@link useBulkDeleteLeads} — which deletes checked rows the
 * client can enumerate — the set here is defined on the server, so the client has no list to iterate
 * and no business deciding what is in scope. `confirm` is sent because the endpoint requires it;
 * that check is the API's, so a call made any other way is refused too.
 *
 * Invalidates exactly what the existing bulk delete invalidates, for the same reason: archiving
 * changes the list, the dashboards, My Performance and the feeds.
 */
export function useDeleteAllLeads() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: () =>
      apiClient
        .post("/leads/destroy-all", { confirm: true })
        .then((r) => r.data.data.archived as number),
    onSuccess: (archived) => {
      queryClient.invalidateQueries({ queryKey: queryKeys.leads.all });
      queryClient.invalidateQueries({ queryKey: queryKeys.dashboard.all });
      queryClient.invalidateQueries({ queryKey: queryKeys.performance.all });
      queryClient.invalidateQueries({ queryKey: queryKeys.activityLogs.all });

      // Says "archived", not "deleted" — the leads are recoverable from Archive, and the toast is
      // the last chance to tell the user that before they go looking for them.
      toast.success(
        archived === 0
          ? "There were no leads to archive."
          : `${archived} lead${archived === 1 ? "" : "s"} moved to Archive`,
      );
    },
    onError: (err) => toast.error(getApiErrorMessage(err, "Failed to archive the leads")),
  });
}
