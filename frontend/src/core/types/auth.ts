// Shared auth types. These mirror the backend's auth payload shape
// (AuthController: { token, user, roles }).

// Platform/HRIS core roles used by the shared shell (the CRM & PMS modules carry
// their own per-system role unions). Per-system model: hr_employee = base HRIS
// self-service, hr_officer = HRIS management, super_admin = all modules.
export type Role =
  | "hr_employee"
  | "hr_officer"
  | "super_admin"
  | "acc_admin"
  | "acc_accountant"
  | "acc_viewer"
  | "inv_admin"
  | "inv_staff"
  | "inv_viewer"
  | "kiosk_bd"
  | "kiosk_client"
  | "booking_admin"
  | "booking_staff"
  | "booking_viewer";

export interface AuthUser {
  id: number;
  name: string;
  email: string;
  /**
   * Effective permission names (roles ∪ direct), e.g. "hr.employees.view",
   * "crm.leads.create". Supplied by the platform UserResource. Used by the
   * launcher + login redirect to compute which modules the user can enter.
   */
  permissions: string[];
  /** CRM Team-Accounts account status (null on users without one). */
  status?: string | null;
  /** ISO timestamp of the user's previous login (null if never). */
  last_login_at?: string | null;
}

/** Minimal employee context carried in the auth payload. */
export interface AuthEmployee {
  id: number;
  job_title: string;
  department: string | null;
  /** Management staff: fixed salary + exempt from attendance (self-service clock hidden). */
  is_management: boolean;
}

/**
 * Response body of POST /auth/login (the inside of the `data` envelope). The
 * session itself arrives as an httpOnly cookie, never in the body.
 */
export interface LoginResponse {
  user: AuthUser;
  roles: Role[];
  employee: AuthEmployee | null;
}

/** Response body of GET /auth/me (the inside of the `data` envelope). */
export interface MeResponse {
  user: AuthUser;
  roles: Role[];
  employee: AuthEmployee | null;
}

/** One browser the user is signed in on (GET /auth/sessions). */
export interface SignedInDevice {
  /** SHA-256 of the session id. The id itself is a credential and never leaves the server. */
  key: string;
  /** "Chrome on Windows" -- enough to recognise a device, not to fingerprint one. */
  device: string;
  user_agent: string | null;
  ip_address: string | null;
  last_active_at: string;
  /** The browser asking. */
  current: boolean;
}
