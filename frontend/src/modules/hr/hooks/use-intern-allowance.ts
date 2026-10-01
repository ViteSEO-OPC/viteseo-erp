"use client";

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";

import {
  deleteInternAllowanceVersion,
  getInternAllowanceSettings,
  previewInternAllowance,
  saveInternAllowanceSettings,
} from "@/modules/hr/api/intern-allowance";
import type {
  InternAllowanceSaveInput,
  InternAllowanceSettings,
  InternPreviewSample,
} from "@/modules/hr/types/intern-allowance";

const KEY = ["hr", "intern-allowance"] as const;

/** HR-only endpoint — pass `enabled: false` wherever the viewer might not be HR. */
export function useInternAllowanceSettings(enabled = true) {
  return useQuery({ queryKey: KEY, queryFn: getInternAllowanceSettings, enabled });
}

export function useSaveInternAllowanceSettings() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (input: InternAllowanceSaveInput) => saveInternAllowanceSettings(input),
    onSuccess: (overview) => {
      qc.setQueryData(KEY, overview);
      // A payslip priced under the old rules is not repriced, but the employee record's
      // "company default" figure is read from here.
      qc.invalidateQueries({ queryKey: ["hr", "employees"] });
    },
  });
}

export function useDeleteInternAllowanceVersion() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (date: string) => deleteInternAllowanceVersion(date),
    onSuccess: (overview) => qc.setQueryData(KEY, overview),
  });
}

/**
 * The Calculation Preview. A query keyed by the (already debounced) settings and sample, so an
 * unchanged form never asks twice and the last result stays on screen while the next one loads.
 */
export function useInternAllowancePreview(
  settings: InternAllowanceSettings | null,
  sample: InternPreviewSample,
) {
  return useQuery({
    queryKey: [...KEY, "preview", settings, sample],
    queryFn: () => previewInternAllowance(settings as InternAllowanceSettings, sample),
    enabled: settings !== null,
    placeholderData: (previous) => previous,
    retry: false,
  });
}
