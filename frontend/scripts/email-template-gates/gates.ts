/**
 * Email template management gates.
 *
 * Run with:  npm run email-templates:gates        (from frontend/)
 *
 * Same recipe as scripts/email-render-gates/: `frontend/` has no test runner, so Node strips the
 * TypeScript and copilot-gates/loader.mjs bridges "@/". The gates drive the REAL helpers the Settings →
 * Email Templates page uses.
 *
 * The cases mirror EmailTemplateManagementTest on the backend, so the form refuses what the server
 * refuses and the preview substitutes what TemplateRenderer substitutes.
 *
 * A  UNKNOWN PLACEHOLDERS — whitelist keys pass, with or without inner spaces; anything else is named.
 * B  STRAY BRACES — a half-typed `{{first_name}` or `first_name}}` is caught.
 * C  FIELD MESSAGE — null when usable, a sentence when not.
 * D  PREVIEW — known keys take their sample, unknown lowercase keys go blank, anything else is untouched.
 * E  CARET INSERTION — inserts at the caret, replaces a selection, and reports where the caret lands.
 * F  STATIC — no dangerouslySetInnerHTML in the feature; the preview renders through EmailPlainText.
 */
import { readFileSync, readdirSync } from "node:fs";
import {
  hasStrayBraces,
  insertAt,
  placeholderProblem,
  renderSample,
  unknownPlaceholders,
} from "@/modules/crm/features/email-templates/lib/email-template-text";

let failed = 0;
function gate(name: string, checks: [string, boolean][]) {
  const failures = checks.filter(([, ok]) => !ok).map(([label]) => label);
  const pass = failures.length === 0;
  if (!pass) failed++;
  console.log(`${pass ? "PASS" : "FAIL"}  ${name.padEnd(46)} ${checks.length - failures.length}/${checks.length}`);
  for (const f of failures.slice(0, 10)) console.log(`         ${f}`);
}

const same = (a: unknown, b: unknown) => JSON.stringify(a) === JSON.stringify(b);

// The shape GET /settings/email-templates sends as meta.tokens.
const TOKENS = [
  { key: "first_name", label: "Contact first name", sample: "Maria" },
  { key: "contact_name", label: "Contact full name", sample: "Maria Santos" },
  { key: "business_name", label: "Business name", sample: "Santos Bakery" },
  { key: "industry", label: "Industry", sample: "Bakery" },
  { key: "city", label: "City", sample: "Quezon City" },
  { key: "sender_name", label: "Sender name", sample: "Alex Reyes" },
];

// ── A  unknown placeholders ─────────────────────────────────────────────────────────────────
{
  const cases: [string, string[]][] = [
    ["Call me at {{phone}}", ["{{phone}}"]],
    ["Hi {{FirstName}}", ["{{FirstName}}"]],
    ["Hi {{ first name }}", ["{{ first name }}"]],
    ["Hi {{ first_name }}", []],
    [TOKENS.map((t) => `{{${t.key}}}`).join(" "), []],
    ["{{phone}} and {{phone}}", ["{{phone}}"]],
    ["No placeholders at all.", []],
  ];
  gate(
    "A  unknown placeholders",
    cases.map(([text, expected]) => [`${JSON.stringify(text)} → ${JSON.stringify(expected)}`, same(unknownPlaceholders(text, TOKENS), expected)]),
  );
}

// ── B  stray braces ─────────────────────────────────────────────────────────────────────────
{
  const cases: [string, boolean][] = [
    ["Hi {{first_name}", true],
    ["Hi first_name}}", true],
    ["Hi {{first_name}}", false],
    ["Hi {single} braces are fine", false],
    ["", false],
  ];
  gate(
    "B  stray braces",
    cases.map(([text, expected]) => [`${JSON.stringify(text)} → ${expected}`, hasStrayBraces(text) === expected]),
  );
}

// ── C  field message ────────────────────────────────────────────────────────────────────────
gate("C  field message", [
  ["usable text → null", placeholderProblem("Hi {{first_name}}, from {{sender_name}}", TOKENS) === null],
  ["unknown placeholder names it", placeholderProblem("{{phone}}", TOKENS)?.includes("{{phone}}") === true],
  ["stray braces explained", placeholderProblem("Hi {{first_name}", TOKENS)?.includes("missing its opening") === true],
]);

// ── D  preview ──────────────────────────────────────────────────────────────────────────────
gate("D  preview", [
  ["known key takes its sample", renderSample("Hi {{first_name}}", TOKENS) === "Hi Maria"],
  ["inner spaces tolerated, like the renderer", renderSample("{{ business_name }}", TOKENS) === "Santos Bakery"],
  ["unknown lowercase key goes blank", renderSample("x{{unknown_key}}y", TOKENS) === "xy"],
  ["wrong case left as typed", renderSample("Hi {{FirstName}}", TOKENS) === "Hi {{FirstName}}"],
  ["every occurrence replaced", renderSample("{{city}}, {{city}}", TOKENS) === "Quezon City, Quezon City"],
]);

// ── E  caret insertion ──────────────────────────────────────────────────────────────────────
gate("E  caret insertion", [
  ["inserts at the caret", same(insertAt("Hi ,", 3, 3, "{{first_name}}"), { value: "Hi {{first_name}},", caret: 17 })],
  ["replaces a selection", same(insertAt("Hi NAME,", 3, 7, "{{first_name}}"), { value: "Hi {{first_name}},", caret: 17 })],
  ["appends at the end", same(insertAt("Hi", 2, 2, "!"), { value: "Hi!", caret: 3 })],
]);

// ── F  static ───────────────────────────────────────────────────────────────────────────────
{
  const dir = new URL("../../src/modules/crm/features/email-templates/components/", import.meta.url);
  const files = readdirSync(dir).map((name) => [name, readFileSync(new URL(name, dir), "utf8")] as const);
  const preview = files.find(([name]) => name === "email-template-preview.tsx")?.[1] ?? "";

  gate("F  static", [
    ...files.map(([name, text]): [string, boolean] => [`no dangerouslySetInnerHTML in ${name}`, !/dangerouslySetInnerHTML\s*[=:]/.test(text)]),
    ["preview renders through EmailPlainText", preview.includes("<EmailPlainText")],
  ]);
}

console.log(failed === 0 ? "\nALL GATES PASS" : `\n${failed} GATE(S) FAILED`);
process.exit(failed === 0 ? 0 : 1);
