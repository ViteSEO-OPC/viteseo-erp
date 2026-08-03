import { apiClient } from "@/core/api-client";
import type {
  Employee,
  EmployeeInput,
  EmployeeUpdateInput,
  EmployeeDocument,
  MyDocument,
  SalaryHistoryEntry,
  StatutoryPreviewRow,
} from "@/modules/hr/types/employee";

export async function getEmployees(search?: string): Promise<Employee[]> {
  const res = await apiClient.get("/hr/employees", {
    params: search ? { search } : undefined,
  });
  return res.data.data;
}

export async function getEmployee(id: number): Promise<Employee> {
  const res = await apiClient.get(`/hr/employees/${id}`);
  return res.data.data;
}

export async function createEmployee(input: EmployeeInput): Promise<Employee> {
  const res = await apiClient.post("/hr/employees", input);
  return res.data.data;
}

/** Partial by design: only the fields sent are written (see EmployeeUpdateInput). */
export async function updateEmployee(
  id: number,
  input: EmployeeUpdateInput,
): Promise<Employee> {
  const res = await apiClient.patch(`/hr/employees/${id}`, input);
  return res.data.data;
}

export async function uploadEmployeePhoto(id: number, photo: File): Promise<Employee> {
  const form = new FormData();
  form.append("photo", photo);
  const res = await apiClient.post(`/hr/employees/${id}/photo`, form, {
    headers: { "Content-Type": "multipart/form-data" },
  });
  return res.data.data;
}

export async function deleteEmployeePhoto(id: number): Promise<Employee> {
  const res = await apiClient.delete(`/hr/employees/${id}/photo`);
  return res.data.data;
}

/**
 * What the government rates would charge this employee, beside any per-employee override on
 * their profile (Slice B7). Its own request rather than a field on the employee record: it
 * resolves the dated rate versions, which the employee LIST has no reason to pay for.
 */
export async function getStatutoryPreview(id: number): Promise<StatutoryPreviewRow[]> {
  const res = await apiClient.get(`/hr/employees/${id}/statutory-preview`);
  return res.data.data;
}

export async function getSalaryHistory(id: number): Promise<SalaryHistoryEntry[]> {
  const res = await apiClient.get(`/hr/employees/${id}/salary-history`);
  return res.data.data;
}

export async function activateEmployee(id: number): Promise<Employee> {
  const res = await apiClient.post(`/hr/employees/${id}/activate`);
  return res.data.data;
}

/** Undo the above — puts the "Onboarding" tag back on the record. */
export async function reopenOnboarding(id: number, reason?: string): Promise<Employee> {
  const res = await apiClient.post(`/hr/employees/${id}/reopen-onboarding`, { reason });
  return res.data.data;
}

/** Grant or revoke HR-officer access for this employee's user account. */
export async function setHrRole(
  id: number,
  isHrOfficer: boolean,
): Promise<Employee> {
  const res = await apiClient.post(`/hr/employees/${id}/hr-role`, {
    is_hr_officer: isHrOfficer,
  });
  return res.data.data;
}

export async function getEmployeeDocuments(id: number): Promise<EmployeeDocument[]> {
  const res = await apiClient.get(`/hr/employees/${id}/documents`);
  return res.data.data;
}

/** The caller's own HR-uploaded documents (listed; download gated by an access request). */
export async function getMyDocuments(): Promise<MyDocument[]> {
  const res = await apiClient.get("/hr/me/documents");
  return res.data.data;
}

export async function uploadEmployeeDocument(
  id: number,
  file: File,
  label: "Uploaded" | "Signed" = "Uploaded",
): Promise<EmployeeDocument> {
  const form = new FormData();
  form.append("file", file);
  form.append("name", file.name);
  form.append("label", label);
  const res = await apiClient.post(`/hr/employees/${id}/documents`, form, {
    headers: { "Content-Type": "multipart/form-data" },
  });
  return res.data.data;
}

export async function deleteEmployeeDocument(id: number, documentId: number): Promise<void> {
  await apiClient.delete(`/hr/employees/${id}/documents/${documentId}`);
}

/** The logged-in user's own employee record (identity from the token). */
export async function getMyEmployee(): Promise<Employee> {
  const res = await apiClient.get("/hr/me/employee");
  return res.data.data;
}

// --- Loans & cash advances (Slice 11b; policies + consent in B15) -----------------

export interface EmployeeLoan {
  id: number;
  employee_id: number;
  type: "loan" | "cash_advance" | "other";
  reference: string | null;
  principal: number;
  per_period_amount: number;
  start_date: string;
  /**
   * Slice B15 — a loan no longer starts collectible. `pending_authorization` is the state that
   * matters: the employee has not yet consented to the deduction, and payroll skips it.
   */
  status:
    | "requested"
    | "rejected"
    | "pending_authorization"
    | "active"
    | "settled"
    | "cancelled"
    | "on_hold";
  notes: string | null;
  /** Derived from the payment ledger — never a stored column. */
  paid: number;
  outstanding: number;
  // Slice B15 — the policy behind it, and the consent that makes any deduction lawful.
  benefit_program_id: number | null;
  policy_name?: string | null;
  requested_at: string | null;
  approved_at: string | null;
  decision_note: string | null;
  authorized_at: string | null;
  needs_authorization: boolean;
  is_collectible: boolean;
}

export interface EmployeeLoanInput {
  benefit_program_id?: number | null;
  type?: EmployeeLoan["type"];
  reference?: string | null;
  principal: number;
  per_period_amount: number;
  start_date: string;
  notes?: string | null;
}

export async function getEmployeeLoans(id: number): Promise<EmployeeLoan[]> {
  const res = await apiClient.get(`/hr/employees/${id}/loans`);
  return res.data.data;
}

export async function createEmployeeLoan(
  id: number,
  input: EmployeeLoanInput,
): Promise<EmployeeLoan> {
  const res = await apiClient.post(`/hr/employees/${id}/loans`, input);
  return res.data.data;
}

export async function cancelEmployeeLoan(
  loanId: number,
  reason: string,
): Promise<EmployeeLoan> {
  const res = await apiClient.post(`/hr/loans/${loanId}/cancel`, { reason });
  return res.data.data;
}

/** Undo a cancellation — payroll resumes collecting from the next run. */
export async function reopenEmployeeLoan(
  loanId: number,
  reason?: string,
): Promise<EmployeeLoan> {
  const res = await apiClient.post(`/hr/loans/${loanId}/reopen`, { reason });
  return res.data.data;
}

// --- The employee's own loans (Slice B15) -----------------------------------------
//
// Separate from the HR endpoints above: these resolve the employee from the token, and the
// authorization is the employee's own act — Labor Code Art. 113 wants the worker's consent, not
// a record HR could create on their behalf.

export async function getMyLoans(): Promise<EmployeeLoan[]> {
  const res = await apiClient.get("/hr/me/loans");
  return res.data.data;
}

export async function requestLoan(input: {
  benefit_program_id: number;
  principal: number;
  per_period_amount: number;
  start_date: string;
  notes?: string | null;
}): Promise<EmployeeLoan> {
  const res = await apiClient.post("/hr/me/loans", input);
  return res.data.data;
}

/** Consent to the deduction. Nothing is collected before this. */
export async function authorizeLoan(loanId: number): Promise<EmployeeLoan> {
  const res = await apiClient.post(`/hr/me/loans/${loanId}/authorize`);
  return res.data.data;
}

export async function approveLoan(loanId: number, note?: string): Promise<EmployeeLoan> {
  const res = await apiClient.post(`/hr/loans/${loanId}/approve`, { note });
  return res.data.data;
}

export async function rejectLoan(loanId: number, reason: string): Promise<EmployeeLoan> {
  const res = await apiClient.post(`/hr/loans/${loanId}/reject`, { reason });
  return res.data.data;
}
