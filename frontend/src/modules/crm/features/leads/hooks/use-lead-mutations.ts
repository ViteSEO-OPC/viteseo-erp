"use client";

import { useMutation, useQueryClient } from "@tanstack/react-query";
import { isCancel } from "axios";
import { toast } from "sonner";
import { apiClient } from "@/modules/crm/lib/axios";
import { getApiErrorMessage } from "@/modules/crm/lib/api-error";
import { queryKeys } from "@/modules/crm/lib/query-keys";
import type {
  ImportResult,
  Lead,
  LeadWritePayload,
} from "@/modules/crm/features/leads/types/lead-types";
import type { PlannerEvent } from "@/modules/crm/features/calendar/types/calendar-types";

/** Create a lead. Omitted fields fall through to backend/DB defaults. */
export function useCreateLead() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (payload: LeadWritePayload) =>
      apiClient.post("/leads", payload).then((r) => r.data.data as Lead),
    onSuccess: (lead) => {
      queryClient.invalidateQueries({ queryKey: queryKeys.leads.lists() });
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
        .patch(`/leads/${id}/assign`, { assigned_to: Number(assignedTo) })
        .then((r) => r.data.data as Lead),
    onSuccess: (lead) => {
      queryClient.invalidateQueries({ queryKey: queryKeys.leads.lists() });
      queryClient.invalidateQueries({ queryKey: queryKeys.leads.detail(lead.id) });
      // The lead.assigned Activity surfaces in the feed(s).
      queryClient.invalidateQueries({ queryKey: queryKeys.dashboard.recentActivity() });
      queryClient.invalidateQueries({ queryKey: queryKeys.activityLogs.all });
      toast.success(`Assigned to ${lead.assignedTo?.name ?? "BD"}`);
    },
    onError: (err) => {
      toast.error(getApiErrorMessage(err, "Failed to assign lead"));
    },
  });
}

export type ImportLeadsVars = {
  file: File;
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
    mutationFn: async ({ file, dryRun, assignedTo, onUploadProgress, signal }: ImportLeadsVars) => {
      const form = new FormData();
      form.append("file", file);
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
      return res.data.data as ImportResult;
    },
    onSuccess: (result) => {
      if (!result.dryRun) {
        queryClient.invalidateQueries({ queryKey: queryKeys.leads.lists() });
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

/**
 * Schedule a follow-up for a lead (Lead Details "Schedule follow-up" Quick Action). Hits the
 * authoritative POST /leads/{id}/follow-ups, which delegates to the single FollowUpScheduler — the
 * SAME creation flow as Cold Calling → Scheduled — so follow-ups never gain a second path. A bare
 * call (no body) creates an undated To-Do; a date/note may be supplied. Invalidating the shared
 * Planner cache refreshes the Follow-ups inbox and every calendar view together.
 */
export function useScheduleFollowUp() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: ({ leadId, body }: { leadId: string; body?: Record<string, unknown> }) =>
      apiClient.post(`/leads/${leadId}/follow-ups`, body ?? {}).then((r) => r.data.data as PlannerEvent),
    onSuccess: (_event, { leadId }) => {
      queryClient.invalidateQueries({ queryKey: queryKeys.planner.all }); // Follow-ups inbox + calendar
      queryClient.invalidateQueries({ queryKey: queryKeys.leads.detail(leadId) }); // lead timeline
      queryClient.invalidateQueries({ queryKey: queryKeys.activityLogs.all }); // planner-event.created Activity
      toast.success("Follow-up added to your Planner");
    },
    onError: (err) => {
      toast.error(getApiErrorMessage(err, "Failed to schedule follow-up"));
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
      toast.success("Lead deleted");
    },
    onError: (err) => {
      toast.error(getApiErrorMessage(err, "Failed to delete lead"));
    },
  });
}
