"use client";

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";

import {
  getCompanyProfile,
  updateCompanyProfile,
  uploadCompanyLogo,
  deleteCompanyLogo,
} from "@/modules/hr/api/company";
import type { CompanyProfileInput } from "@/modules/hr/types/company";

const COMPANY_KEY = ["hr", "company-profile"] as const;

/**
 * The employer's own details. Read by the settings form AND by the payslip document,
 * which every employee opens — so the endpoint is open to any authenticated user, and this
 * is kept fresh for a while: it changes about once a year.
 */
export function useCompanyProfile() {
  return useQuery({
    queryKey: COMPANY_KEY,
    queryFn: getCompanyProfile,
    staleTime: 5 * 60 * 1000,
  });
}

export function useUpdateCompanyProfile() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (input: CompanyProfileInput) => updateCompanyProfile(input),
    onSuccess: (data) => {
      qc.setQueryData(COMPANY_KEY, data);
      qc.invalidateQueries({ queryKey: COMPANY_KEY });
    },
  });
}

export function useUploadCompanyLogo() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (logo: File) => uploadCompanyLogo(logo),
    onSuccess: (data) => qc.setQueryData(COMPANY_KEY, data),
  });
}

export function useDeleteCompanyLogo() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: () => deleteCompanyLogo(),
    onSuccess: (data) => qc.setQueryData(COMPANY_KEY, data),
  });
}
