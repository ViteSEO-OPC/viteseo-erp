import { apiClient } from "@/core/api-client";
import type {
  Employee,
  EmployeeInput,
  EmployeeUpdateInput,
  EmployeeDocument,
  EmploymentHistoryEntry,
  MyDocument,
  SalaryHistoryEntry,
} from "@/modules/hr/types/employee";

/**
 * The employee directory. `status` defaults to "active" server-side.
 *
 * MF-18 — "former" is what makes a rehire reachable: a separated employee is soft-deleted, so the
 * default directory cannot show them and there was no way to find someone to take back.
 */
export async function getEmployees(
  search?: string,
  status?: "active" | "former" | "all",
): Promise<Employee[]> {
  const res = await apiClient.get("/hr/employees", {
    params: {
      ...(search ? { search } : {}),
      ...(status && status !== "active" ? { status } : {}),
    },
  });
  return res.data.data;
}

/** MF-18 — end employment. `separation_date` is the last WORKING day; payroll prorates against it. */
export async function separateEmployee(
  id: number,
  input: { separation_type: "voluntary" | "involuntary"; separation_date: string; reason?: string },
): Promise<Employee> {
  const res = await apiClient.post(`/hr/employees/${id}/separate`, input);
  return res.data.data;
}

/**
 * MF-18 — undo a separation. Service is CONTINUOUS: hire date, leave credits and loan
 * authorisations all stand. For a genuinely new relationship use `rehireEmployee` instead.
 */
export async function reinstateEmployee(id: number, reason?: string): Promise<Employee> {
  const res = await apiClient.post(`/hr/employees/${id}/reinstate`, { reason });
  return res.data.data;
}

/**
 * MF-18 — take someone back as a NEW relationship. Tenure restarts from `hire_date`, leave is
 * seeded fresh, and any outstanding loan drops back to needing the employee's consent (Art. 113).
 */
export async function rehireEmployee(
  id: number,
  input: { hire_date: string; reason?: string },
): Promise<Employee> {
  const res = await apiClient.post(`/hr/employees/${id}/rehire`, input);
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


export async function getSalaryHistory(id: number): Promise<SalaryHistoryEntry[]> {
  const res = await apiClient.get(`/hr/employees/${id}/salary-history`);
  return res.data.data;
}

/* -------------------------------------------------------------------------------------------
 * Hand-corrections to the two timelines on the record.
 *
 * Both are still written automatically — the employment one by a job-title change, the
 * compensation one by a salary change. These are the escape hatch for what automation cannot fix.
 *
 * The compensation writes are gated server-side and their errors are meaningful: a 422 on
 * `effective_date` is a QUESTION (confirm and resend with `acknowledge_repricing`), while a 422 on
 * `id` is a refusal with no override. See SalaryHistoryService on the backend.
 * ------------------------------------------------------------------------------------------- */

export interface EmploymentHistoryInput {
  title: string;
  start_date: string;
  /** null = still in this role. Only one row may be open at a time. */
  end_date: string | null;
  status?: string;
  reason?: string | null;
}

export async function createEmploymentHistory(
  employeeId: number,
  input: EmploymentHistoryInput,
): Promise<EmploymentHistoryEntry> {
  const res = await apiClient.post(`/hr/employees/${employeeId}/employment-histories`, input);
  return res.data.data;
}

export async function updateEmploymentHistory(
  id: number,
  input: EmploymentHistoryInput,
): Promise<EmploymentHistoryEntry> {
  const res = await apiClient.patch(`/hr/employment-histories/${id}`, input);
  return res.data.data;
}

export async function deleteEmploymentHistory(id: number): Promise<void> {
  await apiClient.delete(`/hr/employment-histories/${id}`);
}

export interface SalaryHistoryInput {
  /** MONTHLY — the unit stored, the unit payroll prices from, and the unit displayed. */
  base_salary: number;
  effective_date: string;
  change_type?: string;
  reason?: string | null;
  /** Second-call confirmation when the entry prices completed payroll runs. */
  acknowledge_repricing?: boolean;
}

export async function createSalaryHistory(
  employeeId: number,
  input: SalaryHistoryInput,
): Promise<SalaryHistoryEntry> {
  const res = await apiClient.post(`/hr/employees/${employeeId}/salary-histories`, input);
  return res.data.data;
}

export async function updateSalaryHistory(
  id: number,
  input: SalaryHistoryInput,
): Promise<SalaryHistoryEntry> {
  const res = await apiClient.patch(`/hr/salary-histories/${id}`, input);
  return res.data.data;
}

export async function deleteSalaryHistory(id: number): Promise<void> {
  await apiClient.delete(`/hr/salary-histories/${id}`);
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
  /** Only on the cross-employee list (`getAllLoans`) — null on the per-record one. */
  employee?: { id: number; name: string | null } | null;
  type: "loan" | "cash_advance" | "other";
  reference: string | null;
  principal: number;
  /** The PRINCIPAL instalment. Interest and any fee are charged on top, not folded into this. */
  per_period_amount: number;
  start_date: string;
  // FIN-032 — the terms. Diminishing balance: interest is charged each period on the balance still
  // owed, so the stated rate is the effective rate. 0 = interest-free.
  interest_rate: number;
  interest_period: "annual" | "monthly";
  fees: number;
  /** Collection order when net pay is short. Lower runs first. */
  priority: number;
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
  /** Everything collected — principal, interest and fees together. */
  paid: number;
  /**
   * Outstanding PRINCIPAL, which is what interest is charged on. Interest already collected does not
   * reduce it — otherwise a loan would repay itself out of its own interest.
   */
  outstanding: number;
  outstanding_fees: number;
  paid_principal: number;
  paid_interest: number;
  paid_fees: number;
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
  // FIN-032 — all optional; omitted means an interest-free, fee-free loan at default priority.
  interest_rate?: number;
  interest_period?: "annual" | "monthly";
  fees?: number;
  priority?: number;
}

export async function getEmployeeLoans(id: number): Promise<EmployeeLoan[]> {
  const res = await apiClient.get(`/hr/employees/${id}/loans`);
  return res.data.data;
}

/**
 * Every employee's loans, optionally by status — the approvals queue.
 *
 * Without this a loan sitting at `requested` was reachable only from the record it belongs to,
 * so no dashboard or queue could show that it was waiting on HR.
 */
export async function getAllLoans(status?: string): Promise<EmployeeLoan[]> {
  const res = await apiClient.get("/hr/loans", {
    params: status ? { status } : undefined,
  });
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
