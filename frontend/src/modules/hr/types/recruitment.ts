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

/** How an OJT allowance is quoted. */
export const ALLOWANCE_PERIODS = [
  { value: "per_day", label: "Per day" },
  { value: "per_hour", label: "Per hour" },
  { value: "per_month", label: "Per month" },
] as const;

/** Common office locations offered in the recruitment dropdowns (free-text column). */
export const LOCATIONS = ["Manila", "Cebu", "Davao", "Remote", "Hybrid"] as const;

export function employmentTypeLabel(v: string | null): string {
  return EMPLOYMENT_TYPES.find((x) => x.value === v)?.label ?? "—";
}

/**
 * OJT hires follow their own pipeline, quote an allowance instead of a salary, and are
 * asked for a school and practicum hours. Takes either a requisition's `pipeline` or its
 * `employment_type` — the pipeline is derived from the type, so both spell it "ojt".
 */
export function isOjt(pipelineOrType: string | null | undefined): boolean {
  return pipelineOrType === "ojt";
}

/** "₱650.00 per day", or an em dash when the requisition quotes no allowance. */
export function formatAllowance(
  amount: number | null,
  period: string | null,
): string {
  if (amount == null) return "—";
  const money = new Intl.NumberFormat("en-PH", {
    style: "currency",
    currency: "PHP",
  }).format(amount);
  const label = ALLOWANCE_PERIODS.find((p) => p.value === period)?.label;

  return label ? `${money} ${label.toLowerCase()}` : money;
}

/** Stages that expose the "send interview email" action (mirrors the backend). */
export const INTERVIEW_STAGES = ["online_interview", "final_interview"];

/**
 * The email a candidate at a given stage can be sent — type, button label, and the
 * modal's own title. Every one of these opens the compose modal: the server supplies a
 * stage template as the starting text, and HR edits it before sending.
 */
export const STAGE_EMAIL: Record<
  string,
  { type: string; label: string; title: string }
> = {
  online_interview: {
    type: "online_interview",
    label: "online interview invite",
    title: "Online interview invitation",
  },
  final_interview: {
    type: "final_interview",
    label: "final interview invite",
    title: "Final interview invitation",
  },
  assessment: { type: "assessment", label: "assessment email", title: "Assessment" },
  hired: { type: "offer", label: "offer email", title: "Job offer" },
  rejected: { type: "rejection", label: "rejection notice", title: "Rejection notice" },
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
  /** Quoted on every employment type EXCEPT ojt, which carries an allowance instead. */
  salary_range: string | null;
  allowance_amount: number | null;
  allowance_period: string | null;
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
  requisition: { id: number; title: string; pipeline: string } | null;
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
  /** OJT applicants only — the school sending them and the hours it requires. */
  school: string | null;
  required_hours: number | null;
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

/** What the compose modal opens with — the stage template, or the last email of this type. */
export interface CandidateEmailDraft {
  to: string;
  cc: string[];
  bcc: string[];
  reply_to: string | null;
  subject: string;
  body_html: string;
  /** Set when this draft repeats a previous send, so the modal can say so. */
  previous_sent_at: string | null;
}

export interface CandidateEmailAttachment {
  name: string;
  size: number | null;
  mime: string | null;
  /** Short-lived signed URL, null once it expires or if the file is gone. */
  url: string | null;
}

/** One email sent to a candidate — including the attempts that failed. */
export interface CandidateEmail {
  id: number;
  candidate_id: number;
  type: string;
  to_email: string;
  cc: string[];
  bcc: string[];
  reply_to: string | null;
  subject: string;
  body_html: string;
  attachments: CandidateEmailAttachment[];
  status: "sent" | "failed";
  error: string | null;
  sent_at: string | null;
  sent_by: string | null;
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
