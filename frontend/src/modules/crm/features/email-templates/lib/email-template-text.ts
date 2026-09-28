/**
 * Pure helpers for Settings → Email Templates: placeholder checks, the sample-data preview, and caret
 * insertion. Free of React and the DOM so scripts/email-template-gates can drive them from source.
 *
 * The placeholder WHITELIST is never copied here: every function takes the token list the server sends
 * with the templates (EmailTemplateCatalog), so this file cannot drift from TemplateRenderer. The
 * PATTERNS mirror the server's — EmailTemplateCatalog::unknownTokens / hasStrayBraces for validation,
 * TemplateRenderer::substitute for the preview — and the server re-validates every save regardless.
 */
import type {
  TemplateKind,
  TemplateToken,
} from "@/modules/crm/features/email-templates/types/email-template-types";

/** Mirrors StoreEmailTemplateRequest: `subject` is a VARCHAR(255), `body` is capped at 10,000. */
export const SUBJECT_MAX = 255;
export const BODY_MAX = 10_000;

/** "composer" is the Outreach composer's dropdown — named for where a BD meets it. */
export const KIND_LABELS: Record<TemplateKind, string> = {
  composer: "Outreach",
  automation: "Automation",
};

/** Every `{{…}}` the renderer would not substitute: unknown keys, wrong case, inner spaces. */
export function unknownPlaceholders(text: string, tokens: readonly TemplateToken[]): string[] {
  const allowed = new Set(tokens.map((token) => token.key));
  const unknown = new Set<string>();

  for (const match of text.matchAll(/\{\{([\s\S]*?)\}\}/g)) {
    const key = match[1].trim();
    if (!/^[a-z_]+$/.test(key) || !allowed.has(key)) unknown.add(match[0]);
  }

  return [...unknown];
}

/** A lone `{{` or `}}` left once every complete placeholder is removed — a half-typed token. */
export function hasStrayBraces(text: string): boolean {
  const rest = text.replace(/\{\{[\s\S]*?\}\}/g, "");
  return rest.includes("{{") || rest.includes("}}");
}

/** The sentence the form shows under a field, or null when its placeholders are all usable. */
export function placeholderProblem(text: string, tokens: readonly TemplateToken[]): string | null {
  const unknown = unknownPlaceholders(text, tokens);
  if (unknown.length > 0) {
    return `Unknown placeholder ${unknown.join(", ")}. Use only the placeholders listed below.`;
  }
  if (hasStrayBraces(text)) return "A placeholder is missing its opening {{ or closing }}.";
  return null;
}

/**
 * The template as an email reads with sample data. Same rule as TemplateRenderer: a lowercase
 * `{{ key }}` is replaced by its value — or by nothing, when the key is unknown — and anything else is
 * left exactly as typed.
 */
export function renderSample(text: string, tokens: readonly TemplateToken[]): string {
  const samples = new Map(tokens.map((token) => [token.key, token.sample]));
  return text.replace(/\{\{\s*([a-z_]+)\s*\}\}/g, (_match, key: string) => samples.get(key) ?? "");
}

/** Replace the selection [start, end) with `insertion`; returns the new text and where the caret lands. */
export function insertAt(
  value: string,
  start: number,
  end: number,
  insertion: string,
): { value: string; caret: number } {
  return {
    value: value.slice(0, start) + insertion + value.slice(end),
    caret: start + insertion.length,
  };
}
