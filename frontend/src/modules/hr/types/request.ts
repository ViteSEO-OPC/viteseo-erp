// Phase-2 Slice 19 — document + profile-change requests.

import type { EmployeeRef } from "@/modules/hr/types/employee";

export const DOCUMENT_TYPES = [
  { value: "payroll", label: "Payroll record" },
  { value: "leave", label: "Leave record" },
  { value: "attendance", label: "Attendance record" },
  { value: "coe", label: "Certificate of Employment" },
  { value: "other", label: "Other" },
] as const;

// Personal + bank fields an employee can request a change to (mirrors the backend
// ChangeRequestService::FIELDS). `key` is the field on the employee record used to
// show the current value.
export const CHANGE_FIELDS = [
  { value: "first_name", label: "First name" },
  { value: "middle_name", label: "Middle name" },
  { value: "last_name", label: "Last name" },
  { value: "name_extension", label: "Name extension" },
  { value: "phone", label: "Contact number" },
  { value: "gender", label: "Gender" },
  { value: "civil_status", label: "Civil status" },
  { value: "nationality", label: "Nationality" },
  { value: "date_of_birth", label: "Date of birth" },
  { value: "place_of_birth", label: "Place of birth" },
  { value: "address", label: "Home address" },
  { value: "current_address", label: "Current address" },
  { value: "mothers_maiden_name", label: "Mother's maiden name" },
  { value: "fathers_name", label: "Father's name" },
  // Siblings are a structured list, not a single value, so HR edits them on the record.
  { value: "high_school", label: "High school" },
  { value: "college_course", label: "College course" },
  { value: "university", label: "University/College" },
  { value: "emergency_contact_name", label: "Emergency contact name" },
  { value: "emergency_contact_relationship", label: "Emergency contact relationship" },
  { value: "emergency_contact_phone", label: "Emergency contact number" },
  { value: "height", label: "Height" },
  { value: "weight", label: "Weight" },
  { value: "health_condition", label: "Health concern/condition" },
  { value: "bank_name", label: "Bank name" },
  { value: "bank_account", label: "Bank account" },
  { value: "bank_account_type", label: "Bank account type" },
  { value: "bank_account_name", label: "Bank account name" },
] as const;

export interface DocumentRequest {
  id: number;
  type: string;
  other_detail: string | null;
  reason: string | null;
  status: string;
  fulfilled_url: string | null;
  review_note: string | null;
  reviewed_at: string | null;
  /** Only present when the API loaded the reviewer relation. */
  reviewed_by?: string | null;
  employee: EmployeeRef;
  created_at: string;
}

export interface ChangeRequest {
  id: number;
  field: string;
  field_label: string;
  old_value: string | null;
  new_value: string | null;
  status: string;
  review_note: string | null;
  reviewed_at: string | null;
  reviewed_by?: string | null;
  employee: EmployeeRef;
  created_at: string;
}

export function documentTypeLabel(t: string): string {
  return DOCUMENT_TYPES.find((x) => x.value === t)?.label ?? t;
}

export interface PayslipRequest {
  id: number;
  reason: string;
  status: string;
  review_note: string | null;
  reviewed_at: string | null;
  reviewed_by?: string | null;
  payslip_id: number | null;
  payslip_cycle: string | null;
  employee: EmployeeRef;
  created_at: string;
}

export interface PayrollInquiry {
  id: number;
  subject: string | null;
  topic: string | null;
  message: string;
  attachment_url: string | null;
  priority: string;
  status: string;
  resolution_note: string | null;
  resolution_attachments: { url: string; name: string }[];
  resolved_at: string | null;
  resolved_by?: string | null;
  payslip_id: number | null;
  payslip_cycle: string | null;
  employee: EmployeeRef;
  created_at: string;
}
