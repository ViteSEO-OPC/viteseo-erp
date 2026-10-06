import { apiClient } from "@/core/api-client";
import { queueQuery, type QueueParams, type QueuePage } from "@/modules/hr/api/queue";
import type {
  Employee,
  EmployeeListItem,
  EmployeeOption,
  EmployeeInput,
  EmployeeUpdateInput,
  EmployeeDocument,
  EmploymentHistoryEntry,
  MyDocument,
  SalaryHistoryEntry,
} from "@/modules/hr/types/employee";

/** What the directory asks for. Everything here is applied by the database, not the browser. */
export interface EmployeeDirectoryParams {
  search?: string;
  /** Which roster. "former" is what makes a rehire reachable — see below. */
  status?: "active" | "former" | "all";
  /** Department id, as a string from the select. */
  department?: string;
  employment_status?: string;
  /** active | floating | suspended — where current staff stand. */
  work_status?: string;
  page?: number;
  per_page?: number;
}

/** One page of the directory, plus how many people match across every page. */
export interface EmployeeDirectoryPage {
  items: EmployeeListItem[];
  total: number;
}

/**
 * One page of the employee directory. `status` defaults to "active" server-side.
 *
 * "former" is what makes a rehire reachable: a separated employee is soft-deleted, so the
 * default directory cannot show them and there was no way to find someone to take back.
 *
 * Returns the TOTAL alongside the rows because the screen cannot work it out: it holds twelve
 * cards and needs to say how many people matched.
 */
export async function getEmployees(
  params: EmployeeDirectoryParams = {},
): Promise<EmployeeDirectoryPage> {
  const { search, status, department, employment_status, work_status, page, per_page } = params;
  const res = await apiClient.get("/hr/employees", {
    params: {
      ...(search ? { search } : {}),
      ...(status && status !== "active" ? { status } : {}),
      ...(department && department !== "all" ? { department } : {}),
      ...(employment_status && employment_status !== "all" ? { employment_status } : {}),
      ...(work_status && work_status !== "all" ? { work_status } : {}),
      ...(page && page > 1 ? { page } : {}),
      ...(per_page ? { per_page } : {}),
    },
  });
  return { items: res.data.data, total: res.data.meta?.total ?? res.data.data.length };
}

/**
 * Every employee, as picker options. Its own endpoint because a dropdown cannot page — reading
 * the directory gave the selects its first hundred people, so employee 101 could not be chosen
 * as anyone's manager. Four fields per row instead of thirteen.
 */
export async function getEmployeeOptions(): Promise<EmployeeOption[]> {
  const res = await apiClient.get("/hr/employees/options");
  return res.data.data;
}

/** End employment. `separation_date` is the last WORKING day; payroll prorates against it. */
export async function separateEmployee(
  id: number,
  input: { separation_type: "voluntary" | "involuntary"; separation_date: string; reason?: string },
): Promise<Employee> {
  const res = await apiClient.post(`/hr/employees/${id}/separate`, input);
  return res.data.data;
}

/**
 * Undo a separation. Service is CONTINUOUS: hire date, leave credits and loan
 * authorisations all stand. For a genuinely new relationship use `rehireEmployee` instead.
 */
export async function reinstateEmployee(id: number, reason?: string): Promise<Employee> {
  const res = await apiClient.post(`/hr/employees/${id}/reinstate`, { reason });
  return res.data.data;
}

/**
 * Take someone back as a NEW relationship. Tenure restarts from `hire_date`, leave is
 * seeded fresh, and any outstanding loan drops back to needing the employee's consent (Art. 113).
 */
export async function rehireEmployee(
  id: number,
  input: { hire_date: string; reason?: string },
): Promise<Employee> {
  const res = await apiClient.post(`/hr/employees/${id}/rehire`, input);
  return res.data.data;
}

/** What the conversion dialog hands over at the end of an internship. */
export interface ConvertInternInput {
  employment_status: string;
  effective_date: string;
  base_salary: number;
  job_title?: string;
  reason?: string;
  /** Set once HR has seen the warning about repricing a settled payroll run. */
  acknowledge_repricing?: boolean;
}

/**
 * Take an intern on as staff. Not the same as editing Employment status: this closes the
 * internship on the employment timeline, opens the salary history at their first real
 * salary, and raises leave credits from an intern's (zero) to the new status's.
 */
export async function convertIntern(
  id: number,
  input: ConvertInternInput,
): Promise<Employee> {
  const res = await apiClient.post(`/hr/employees/${id}/convert-intern`, input);
  return res.data.data;
}

export async function getEmployee(id: number): Promise<Employee> {
  const res = await apiClient.get(`/hr/employees/${id}`);
  return res.data.data;
}

/**
 * A hire, and the password that lets them in.
 *
 * The password sits BESIDE the envelope's `data`, not inside it, because it is not
 * part of the resource: the server keeps only the hash, so this is the one response
 * that will ever carry it.
 */
export interface CreatedEmployee {
  employee: Employee;
  /**
   * Optional on purpose — an older backend or a proxy that strips unknown keys
   * leaves it undefined, and the caller has to cope rather than show a blank one.
   */
  temporary_password?: string;
}

export async function createEmployee(input: EmployeeInput): Promise<CreatedEmployee> {
  const res = await apiClient.post("/hr/employees", input);
  return { employee: res.data.data, temporary_password: res.data.temporary_password };
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

/* Hand-corrections to the two timelines on the record. */

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

/** Rename a document. The stored file is untouched — only the name on the record changes. */
export async function renameEmployeeDocument(
  id: number,
  documentId: number,
  name: string,
): Promise<EmployeeDocument> {
  const res = await apiClient.patch(`/hr/employees/${id}/documents/${documentId}`, { name });
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

// --- Loans & cash advances (policies + consent in B15) -----------------

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
  // The terms. Diminishing balance: interest is charged each period on the balance still
  // owed, so the stated rate is the effective rate. 0 = interest-free.
  interest_rate: number;
  interest_period: "annual" | "monthly";
  fees: number;
  /** Collection order when net pay is short. Lower runs first. */
  priority: number;
  /**
   * A loan no longer starts collectible. `pending_authorization` is the state that
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
  // The policy behind it, and the consent that makes any deduction lawful.
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
  // All optional; omitted means an interest-free, fee-free loan at default priority.
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
export async function getAllLoans(
  status: string | undefined,
  params: QueueParams,
): Promise<QueuePage<EmployeeLoan>> {
  const res = await apiClient.get("/hr/loans", {
    params: { ...queueQuery(params), ...(status ? { status } : {}) },
  });
  return { items: res.data.data, total: res.data.meta?.total ?? res.data.data.length };
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

// --- The employee's own loans -----------------------------------------

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
