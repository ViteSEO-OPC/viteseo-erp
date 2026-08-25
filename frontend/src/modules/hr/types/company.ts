/** The employer, as edited under Company → Settings and printed on every letterhead. */
export interface CompanyProfile {
  id: number;
  legal_name: string;
  trade_name: string | null;
  /** One line, as it prints. */
  address: string | null;
  tin: string | null;
  sss_employer_number: string | null;
  philhealth_employer_number: string | null;
  pagibig_employer_number: string | null;
  email: string | null;
  phone: string | null;
  website: string | null;
  /** Short-lived signed URL, or null when no logo is set. */
  logo_url: string | null;
}

/** Everything the form edits. The logo has its own upload endpoint. */
export type CompanyProfileInput = Omit<CompanyProfile, "id" | "logo_url">;
