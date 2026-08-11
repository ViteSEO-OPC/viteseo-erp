"use client";

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";

import {
  getDocumentRequests,
  getMyDocumentRequests,
  createDocumentRequest,
  fulfillDocumentRequest,
  rejectDocumentRequest,
  reopenDocumentRequest,
  getChangeRequests,
  getMyChangeRequests,
  createChangeRequest,
  approveChangeRequest,
  rejectChangeRequest,
  reopenChangeRequest,
  getPayslipRequests,
  getMyPayslipRequests,
  createPayslipRequest,
  approvePayslipRequest,
  rejectPayslipRequest,
  reopenPayslipRequest,
  getPayrollInquiries,
  getMyPayrollInquiries,
  createPayrollInquiry,
  progressPayrollInquiry,
  resolvePayrollInquiry,
  reopenPayrollInquiry,
  cancelPayrollInquiry,
} from "@/modules/hr/api/requests";

const DOCS_KEY = ["hr", "document-requests"] as const;
const MY_DOCS_KEY = ["hr", "me", "document-requests"] as const;
const CHANGES_KEY = ["hr", "change-requests"] as const;
const MY_CHANGES_KEY = ["hr", "me", "change-requests"] as const;
const PAYSLIP_REQS_KEY = ["hr", "payslip-requests"] as const;
const MY_PAYSLIP_REQS_KEY = ["hr", "me", "payslip-requests"] as const;
const INQUIRIES_KEY = ["hr", "payroll-inquiries"] as const;
const MY_INQUIRIES_KEY = ["hr", "me", "payroll-inquiries"] as const;

// --- Document requests ---

export function useDocumentRequests() {
  return useQuery({ queryKey: DOCS_KEY, queryFn: getDocumentRequests });
}

export function useMyDocumentRequests() {
  return useQuery({ queryKey: MY_DOCS_KEY, queryFn: getMyDocumentRequests });
}

export function useCreateDocumentRequest() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: createDocumentRequest,
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: MY_DOCS_KEY });
      // Requesting access to an uploaded doc flips its state in the documents list.
      qc.invalidateQueries({ queryKey: ["hr", "me", "documents"] });
    },
  });
}

export function useFulfillDocumentRequest() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: ({ id, note }: { id: number; note?: string }) => fulfillDocumentRequest(id, note),
    onSuccess: () => qc.invalidateQueries({ queryKey: DOCS_KEY }),
  });
}

export function useRejectDocumentRequest() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: ({ id, reason }: { id: number; reason: string }) => rejectDocumentRequest(id, reason),
    onSuccess: () => qc.invalidateQueries({ queryKey: DOCS_KEY }),
  });
}

export function useReopenDocumentRequest() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: ({ id, reason }: { id: number; reason?: string }) => reopenDocumentRequest(id, reason),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: DOCS_KEY });
      // Re-locks the employee's download, so their own views change too.
      qc.invalidateQueries({ queryKey: MY_DOCS_KEY });
      qc.invalidateQueries({ queryKey: ["hr", "me", "documents"] });
    },
  });
}

// --- Change requests ---

export function useChangeRequests() {
  return useQuery({ queryKey: CHANGES_KEY, queryFn: getChangeRequests });
}

export function useMyChangeRequests() {
  return useQuery({ queryKey: MY_CHANGES_KEY, queryFn: getMyChangeRequests });
}

export function useCreateChangeRequest() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: createChangeRequest,
    onSuccess: () => qc.invalidateQueries({ queryKey: MY_CHANGES_KEY }),
  });
}

export function useApproveChangeRequest() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (id: number) => approveChangeRequest(id),
    // Approval writes onto the employee record too — refresh employees.
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: CHANGES_KEY });
      qc.invalidateQueries({ queryKey: ["hr", "employees"] });
    },
  });
}

export function useRejectChangeRequest() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: ({ id, reason }: { id: number; reason: string }) => rejectChangeRequest(id, reason),
    onSuccess: () => qc.invalidateQueries({ queryKey: CHANGES_KEY }),
  });
}

export function useReopenChangeRequest() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: ({ id, reason }: { id: number; reason?: string }) => reopenChangeRequest(id, reason),
    // May put the employee's previous value back, so refresh employees too.
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: CHANGES_KEY });
      qc.invalidateQueries({ queryKey: ["hr", "employees"] });
    },
  });
}

// --- Payslip requests ---

export function usePayslipRequests() {
  return useQuery({ queryKey: PAYSLIP_REQS_KEY, queryFn: getPayslipRequests });
}

export function useMyPayslipRequests() {
  return useQuery({ queryKey: MY_PAYSLIP_REQS_KEY, queryFn: getMyPayslipRequests });
}

export function useCreatePayslipRequest() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: createPayslipRequest,
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: MY_PAYSLIP_REQS_KEY });
      // The payslip history row reflects hardcopy_status — refresh it so the
      // requested period flips to "Copy pending" right away.
      qc.invalidateQueries({ queryKey: ["hr", "me", "payslips"] });
    },
  });
}

export function useApprovePayslipRequest() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (id: number) => approvePayslipRequest(id),
    onSuccess: () => qc.invalidateQueries({ queryKey: PAYSLIP_REQS_KEY }),
  });
}

export function useRejectPayslipRequest() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: ({ id, reason }: { id: number; reason: string }) => rejectPayslipRequest(id, reason),
    onSuccess: () => qc.invalidateQueries({ queryKey: PAYSLIP_REQS_KEY }),
  });
}

export function useReopenPayslipRequest() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: ({ id, reason }: { id: number; reason?: string }) => reopenPayslipRequest(id, reason),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: PAYSLIP_REQS_KEY });
      // The employee's payslip history shows hardcopy_status, which just moved.
      qc.invalidateQueries({ queryKey: MY_PAYSLIP_REQS_KEY });
      qc.invalidateQueries({ queryKey: ["hr", "me", "payslips"] });
    },
  });
}

// --- Payroll inquiries ---

export function usePayrollInquiries() {
  return useQuery({ queryKey: INQUIRIES_KEY, queryFn: getPayrollInquiries });
}

export function useMyPayrollInquiries() {
  return useQuery({ queryKey: MY_INQUIRIES_KEY, queryFn: getMyPayrollInquiries });
}

export function useCreatePayrollInquiry() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: createPayrollInquiry,
    onSuccess: () => qc.invalidateQueries({ queryKey: MY_INQUIRIES_KEY }),
  });
}

export function useProgressPayrollInquiry() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (id: number) => progressPayrollInquiry(id),
    onSuccess: () => qc.invalidateQueries({ queryKey: INQUIRIES_KEY }),
  });
}

export function useResolvePayrollInquiry() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: ({ id, note, files }: { id: number; note: string; files?: File[] }) =>
      resolvePayrollInquiry(id, note, files),
    onSuccess: () => qc.invalidateQueries({ queryKey: INQUIRIES_KEY }),
  });
}

// The employee withdrawing their own pending inquiry. Reason required.
export function useCancelPayrollInquiry() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: ({ id, reason }: { id: number; reason: string }) =>
      cancelPayrollInquiry(id, reason),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: INQUIRIES_KEY });
      qc.invalidateQueries({ queryKey: MY_INQUIRIES_KEY });
    },
  });
}

export function useReopenPayrollInquiry() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: ({ id, reason }: { id: number; reason?: string }) => reopenPayrollInquiry(id, reason),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: INQUIRIES_KEY });
      qc.invalidateQueries({ queryKey: MY_INQUIRIES_KEY });
    },
  });
}
