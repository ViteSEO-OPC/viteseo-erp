/**
 * Types for Settings → Business Rules (Business Rules Administration). These mirror the API resources
 * 1:1 (camelCase, string IDs) — the UI is a thin client and adds no business logic of its own. `pointValue`
 * is the absolute magnitude; `isSubtractive` says whether to render it as a subtraction (−value). The engine,
 * not the UI, applies caps and computes scores.
 */

export type BusinessRuleCategory = "fit" | "engagement" | "negative";
export type BusinessRuleState = "draft" | "active" | "archived";
export type BusinessRuleAuditAction =
  | "seed"
  | "edit"
  | "enable"
  | "disable"
  | "reorder"
  | "publish"
  | "rollback"
  | "discard_draft";

export type BusinessRule = {
  id: string;
  ruleKey: string;
  description: string;
  /**
   * Null for modules with no scoring semantics (`kpi`): a KPI definition carries neither an
   * arithmetic sign nor a point value, so both come back null rather than as a fabricated 0.
   */
  category: BusinessRuleCategory | null;
  pointValue: number | null;
  isSubtractive: boolean;
  isEnabled: boolean;
  displayOrder: number;
};

export type BusinessRuleVersion = {
  id: string;
  module: string;
  versionNumber: number;
  state: BusinessRuleState;
  computationSpecVersion: string;
  reasonForChange: string | null;
  createdBy: string | null;
  publishedBy: string | null;
  /**
   * Resolved identities — present only when the endpoint eager-loads them (Version History does).
   *
   * Optional rather than merely nullable: `whenLoaded` omits the key entirely on endpoints that do
   * not load the relation, so `undefined` and `null` mean different things — not requested versus
   * requested but unresolvable. The raw `createdBy`/`publishedBy` ids above stay as the stable
   * reference; a version whose author has since been deleted keeps its id but resolves to no name.
   */
  creator?: { id: string; name: string } | null;
  publisher?: { id: string; name: string } | null;
  publishedAt: string | null;
  createdAt: string | null;
  rules: BusinessRule[];
};

export type BusinessRuleAudit = {
  id: string;
  module: string;
  action: BusinessRuleAuditAction;
  ruleKey: string | null;
  previousValue: string | null;
  newValue: string | null;
  versionNumber: number;
  computationSpecVersion: string;
  reasonForChange: string | null;
  userId: string | null;
  user: { id: string; name: string } | null;
  createdAt: string | null;
};

/** The single enabled module in v1. */
export const LEAD_SCORING = "lead_scoring";

export const CATEGORY_LABEL: Record<BusinessRuleCategory, string> = {
  fit: "Fit",
  engagement: "Engagement",
  negative: "Negative",
};

export const ACTION_LABEL: Record<BusinessRuleAuditAction, string> = {
  seed: "Seed",
  edit: "Edit",
  enable: "Enable",
  disable: "Disable",
  reorder: "Reorder",
  publish: "Publish",
  rollback: "Rollback",
  discard_draft: "Discard Draft",
};
