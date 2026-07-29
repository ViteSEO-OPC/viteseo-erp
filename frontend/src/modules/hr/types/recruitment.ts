export const CANDIDATE_SOURCES = [
  "referral",
  "linkedin",
  "jobboard",
  "direct",
  "agency",
] as const;

export const REQ_STAGES = ["draft", "active", "closed"] as const;

export const PIPELINES = [
  { value: "standard", label: "Standard" },
  { value: "ojt", label: "OJT" },
] as const;

export const EMPLOYMENT_TYPES = [
  { value: "full_time", label: "Full-time" },
  { value: "part_time", label: "Part-time" },
  { value: "contract", label: "Contract" },
  { value: "ojt", label: "OJT / Internship" },
  { value: "temporary", label: "Temporary" },
] as const;

/** Common office locations offered in the recruitment dropdowns (free-text column). */
export const LOCATIONS = ["Manila", "Cebu", "Davao", "Remote", "Hybrid"] as const;

export function employmentTypeLabel(v: string | null): string {
  return EMPLOYMENT_TYPES.find((x) => x.value === v)?.label ?? "—";
}

/** Stages that expose the "send interview email" action (mirrors the backend). */
export const INTERVIEW_STAGES = ["online_interview", "final_interview"];

/**
 * The email a candidate at a given stage can be sent — type + button label. All but
 * `assessment` are fixed templates sent in one click; the assessment email is composed
 * by HR first (see AssessmentEmailDialog).
 */
export const STAGE_EMAIL: Record<string, { type: string; label: string }> = {
  online_interview: { type: "online_interview", label: "online interview invite" },
  final_interview: { type: "final_interview", label: "final interview invite" },
  assessment: { type: "assessment", label: "assessment email" },
  hired: { type: "offer", label: "offer email" },
  rejected: { type: "rejection", label: "rejection notice" },
};

/** Reasons offered when rejecting a candidate. */
export const REJECTION_REASONS = [
  "Not qualified",
  "Position filled",
  "Failed assessment",
  "Withdrew",
  "Salary mismatch",
  "Other",
] as const;

export interface StageDef {
  key: string;
  label: string;
}

export interface Requisition {
  id: number;
  title: string;
  pipeline: string;
  employment_type: string | null;
  salary_range: string | null;
  location: string | null;
  job_description: string | null;
  target_start_date: string | null;
  /** The ordered stage flow — the board renders one column per entry. */
  stages: StageDef[];
  department: { id: number; name: string } | null;
  hiring_manager: { id: number; name: string | null } | null;
  openings: number;
  stage: string;
  opened_at: string;
  candidates_count: number;
}

export interface Candidate {
  id: number;
  requisition_id: number;
  requisition: { id: number; title: string } | null;
  name: string;
  email: string | null;
  phone: string | null;
  source: string;
  experience_years: number | null;
  /** null = not in the pipeline yet (table-only until dragged onto a column). */
  stage: string | null;
  rating: number | null;
  /** Free-text salary range, e.g. "₱45,000 - 65,000". */
  expected_salary: string | null;
  location: string | null;
  feedback: string | null;
  notes: string | null;
  /** Email types already sent to this candidate (e.g. ["online_interview"]). */
  emails_sent: string[];
  /** What HR sent in the assessment email — the record of it, for reading back or re-sending. */
  assessment_link: string | null;
  assessment_instructions: string | null;
  /** "yyyy-MM-dd", or null when HR gave no deadline. */
  assessment_deadline: string | null;
  assessment_sent_at: string | null;
  rejection_reason: string | null;
  hired_employee_id: number | null;
  resume_path: string | null;
  resume_url: string | null;
  created_at: string;
}

export function pipelineLabel(p: string): string {
  return PIPELINES.find((x) => x.value === p)?.label ?? p;
}

export function sourceLabel(s: string): string {
  return s === "linkedin"
    ? "LinkedIn"
    : s === "jobboard"
      ? "Job board"
      : s.charAt(0).toUpperCase() + s.slice(1);
}
