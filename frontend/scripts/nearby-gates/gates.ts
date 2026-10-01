/**
 * Nearby Clients gates — geolocation outcome classification.
 *
 * Run with:  npm run nearby:gates        (from frontend/)
 *
 * Same recipe as scripts/calling-gates: `frontend/` has no test runner, Node strips the TypeScript
 * natively, and scripts/copilot-gates/loader.mjs bridges the "@/" alias — so these gates drive the
 * REAL `lib/geolocation` module the hook uses, never a copy that could drift from it.
 *
 * Exits non-zero if any gate fails, so it can be wired into CI unchanged.
 *
 * ── WHAT EACH GATE VERIFIES ──────────────────────────────────────────────────────────────────
 *
 * A  EVERY CAUSE IS DISTINGUISHABLE — the browser's three error codes map to three different
 *    statuses. This is the regression that matters: the callback used to throw its argument away
 *    and answer "denied" every time, which sent people to a permission they had never denied.
 * B  INSECURE ORIGIN IS CAUGHT BEFORE ASKING — an insecure page is named as such rather than being
 *    reported by the browser as a refused permission. This is the actual cause in LAN development.
 * C  UNSUPPORTED IS NOT INSECURE — a browser with no Geolocation API is its own answer.
 * D  A LOCATABLE CONTEXT IS NOT PRE-EMPTED — preflight returns null so the browser is really asked.
 * E  NOTHING IS EVER INVENTED — an unknown or absent error code never becomes "denied", and the
 *    module produces no coordinates of any kind.
 * K  THE COUNTRY IS A RESERVED WORD, NOT A BLOCKLIST — the nationwide mode owns "Philippines" and
 *    every spelling of it, while every other place name on earth stays free text. Typing the country
 *    into the locations box used to buy one provider call over the whole archipelago and present its
 *    handful of results as national coverage.
 * L  THE COUNTRY IS A REAL, DEFAULTED, PHILIPPINES-ONLY SETTING — one supported country, defaulting
 *    to it, with a label for display. The restriction is enforced server-side in three places; this
 *    gate pins the client's half so the UI cannot drift into offering a country nothing can search.
 *    (Gate M covered a Philippines-only board VIEW that has since been removed by product decision:
 *    the pool shows every lead it always did, and PH-only is enforced where it belongs — the API,
 *    the geocoder, Google discovery and the ingest boundary — never by hiding existing rows.)
 * N  ON-DEMAND DISCOVERY IS ADDITIVE — its controls appear only where the capability that backs them
 *    is actually running. On the OpenStreetMap baseline the board stays exactly what it was before
 *    Stage 2, and an unknown or still-loading provider is never read as permission.
 * P  AN UNPAYABLE CREDENTIAL IS NEVER SHOWN AS HEALTHY — a key whose account has no credit answers
 *    200 on the zero-cost probe, so "Verified" used to appear beside a credential that fails 402 on
 *    every real call. The distinct status must survive into the UI, read as a credit problem, and be
 *    judged BEFORE the "currently running" branch.
 *
 * ── AI DISCOVERY (per-run `ai_strategy`) ──
 *
 * Q  SUBMISSION — "Discover with AI" sends the same body as the ordinary run plus `ai_strategy: true`,
 *    for both the scoped and the nationwide shape.
 * R  THE DEFAULT REQUEST IS BYTE-IDENTICAL — an ordinary run carries no `ai_strategy` key at all, not
 *    `false`; the legacy key set is pinned exactly.
 * S  VALIDATION / CAPABILITY — the AI action is offered only on Google Places AND a backend-reported
 *    `available`; unknown, loading, disabled, no-credit and the OSM baseline all withhold it, and the
 *    dialog refuses an AI submit the capability would not allow.
 * T  LOADING AND RUNNING — submitting, queued and running are distinct phases.
 * U  COMPLETION IS TRUTHFUL — `applied` is described only from the run's recorded outcome; a requested
 *    run AI did not shape says so; an ordinary run shows no AI line at all.
 * V  CANCELLATION — `cancelled` is its own phase, and the dialog is wired to the existing cancel API.
 * W  INSUFFICIENT CREDIT — both sources (a refused start, a run stopped mid-way) read as the credit
 *    state, and only a 422 keyed on `ai_strategy` counts as an AI refusal.
 * X  BACKEND FAILURE — an ordinary failure stays `failed`, and is never relabelled as a credit problem.
 * Y  NO INTERNAL MECHANICS OR CREDENTIALS REACH THE UI — none of the feedback verdicts (barren, no_new,
 *    productive, abundant, unmeasured) and no key fields in the AI Discovery code.
 * Z  THE DEFAULT FLOW IS UNCHANGED — Enter and "Run discovery" still start the ordinary run, and the AI
 *    button exists only behind the capability.
 */
import { readFileSync } from "node:fs";
import {
  GEOLOCATION_PERMISSION_DENIED,
  GEOLOCATION_POSITION_UNAVAILABLE,
  GEOLOCATION_TIMEOUT,
  geolocationPreflight,
  geolocationStatusFor,
} from "@/modules/crm/features/nearby-clients/lib/geolocation";
import {
  googleMapsBrowserKey,
  mapProviderFor,
  mapRendererFor,
} from "@/modules/crm/features/nearby-clients/lib/map-provider";
import {
  nearbyEmptyMessage,
  nearbyEmptyReason,
} from "@/modules/crm/features/nearby-clients/lib/empty-reason";
import {
  DEFAULT_COUNTRY,
  SUPPORTED_COUNTRIES,
  countryLabel,
  islandGroupLabel,
  isCountryName,
} from "@/modules/crm/features/nearby-clients/types/discovery-run-types";
import {
  canRunDiscovery,
  canRunNationwide,
  discoveryUnavailableReason,
} from "@/modules/crm/features/nearby-clients/lib/discovery-capability";
import {
  aiOutcomeLabel,
  aiOutcomeMessage,
  buildStartRunBody,
  canRunAiDiscovery,
  discoveryPhase,
  isAiStartRefusal,
} from "@/modules/crm/features/nearby-clients/lib/ai-discovery";
import type { DiscoveryRun } from "@/modules/crm/features/nearby-clients/types/discovery-run-types";

let failed = 0;

function report(label: string, passed: number, total: number, bad: string[]): void {
  const ok = passed === total;
  if (!ok) failed += 1;
  console.log(`${ok ? "PASS" : "FAIL"}  ${label}  (${passed}/${total})`);
  for (const line of bad) console.log(`        ${line}`);
}

const locatable = { geolocation: {} };
const secure = { isSecureContext: true };

// ── A  every cause is distinguishable ────────────────────────────────────────────────────────
{
  const cases: [string, number, string][] = [
    ["permission denied", GEOLOCATION_PERMISSION_DENIED, "denied"],
    ["position unavailable", GEOLOCATION_POSITION_UNAVAILABLE, "unavailable"],
    ["timeout", GEOLOCATION_TIMEOUT, "timeout"],
  ];
  const bad = cases
    .filter(([, code, want]) => geolocationStatusFor({ code }) !== want)
    .map(([label, code, want]) => `${label} → ${geolocationStatusFor({ code })}, want ${want}`);

  // The three must also be three DIFFERENT answers, not merely correct individually.
  const distinct = new Set(cases.map(([, code]) => geolocationStatusFor({ code })));
  if (distinct.size !== 3) bad.push(`collapsed into ${distinct.size} status(es): ${[...distinct].join(",")}`);

  report("A  every geolocation failure is distinguishable", cases.length + 1 - bad.length, cases.length + 1, bad);
}

// ── B  insecure origin is caught before asking ───────────────────────────────────────────────
{
  const bad: string[] = [];
  const insecure = geolocationPreflight(locatable, { isSecureContext: false });
  if (insecure !== "insecure") bad.push(`insecure page → ${insecure}, want insecure`);

  // The browser would have answered PERMISSION_DENIED here, and reporting it as "denied" is the bug
  // this module exists to fix. There is deliberately NO runtime assertion for that: preflight
  // returns "insecure" | "unsupported" | null, so "denied" is unrepresentable — the compiler carries
  // the guarantee. Checking it at runtime was a comparison that could never fail, which is a gate
  // that looks like coverage and provides none.
  report("B  an insecure origin is named, not blamed on the user", 1 - bad.length, 1, bad);
}

// ── C  unsupported is its own answer ─────────────────────────────────────────────────────────
{
  const bad: string[] = [];
  if (geolocationPreflight({}, secure) !== "unsupported") bad.push("no geolocation API → not unsupported");
  if (geolocationPreflight(undefined, secure) !== "unsupported") bad.push("no navigator → not unsupported");
  // A browser without the API must not be reported as an insecure page, and vice versa.
  if (geolocationPreflight({}, { isSecureContext: false }) !== "unsupported") {
    bad.push("missing API outranked by insecure context");
  }
  report("C  an unsupported browser is not an insecure page", 3 - bad.length, 3, bad);
}

// ── D  a locatable context is really asked ───────────────────────────────────────────────────
{
  const bad: string[] = [];
  if (geolocationPreflight(locatable, secure) !== null) bad.push("secure + supported was pre-empted");
  // An environment that does not define isSecureContext must not be declared insecure on the
  // strength of an absent property — that would block locating in every non-DOM context.
  if (geolocationPreflight(locatable, {}) !== null) bad.push("absent isSecureContext treated as insecure");
  if (geolocationPreflight(locatable, undefined) !== null) bad.push("absent window treated as insecure");
  report("D  a locatable context is not pre-empted", 3 - bad.length, 3, bad);
}

// ── E  nothing is invented ───────────────────────────────────────────────────────────────────
{
  const bad: string[] = [];
  // An unknown code is an honest "we did not get a position", never a fabricated cause.
  for (const code of [0, 99, -1]) {
    const status = geolocationStatusFor({ code });
    if (status === "denied") bad.push(`code ${code} → denied (a cause nobody established)`);
    if (status !== "unavailable") bad.push(`code ${code} → ${status}, want unavailable`);
  }
  if (geolocationStatusFor(undefined) !== "unavailable") bad.push("absent error → not unavailable");

  // The module classifies outcomes; it must never be a source of coordinates.
  const source = JSON.stringify({ geolocationPreflight, geolocationStatusFor });
  if (source.includes("latitude") || source.includes("longitude")) bad.push("module references coordinates");

  report("E  no cause and no coordinate is ever invented", 5 - bad.length, 5, bad);
}

// ── F  the map follows the EFFECTIVE discovery provider ──────────────────────────────────────
{
  const cases: [string, string | null | undefined, string][] = [
    ["google places active", "google_places", "google"],
    ["openstreetmap active", "openstreetmap", "openstreetmap"],
    // The AI lane resolves every candidate against an OSM element, so its records carry OSM
    // identities and belong on the OSM map — not Google's.
    ["ai-assisted active", "openai", "openstreetmap"],
    // Anything unrecognised, missing, or not yet loaded falls to the map that needs no credential.
    ["a provider from a newer build", "some_future_provider", "openstreetmap"],
    ["lookup not yet returned", undefined, "openstreetmap"],
    ["lookup failed", null, "openstreetmap"],
  ];
  const bad = cases
    .filter(([, provider, want]) => mapProviderFor(provider) !== want)
    .map(([label, provider, want]) => `${label} → ${mapProviderFor(provider)}, want ${want}`);

  report("F  the map follows the effective discovery provider", cases.length - bad.length, cases.length, bad);
}

// ── G  the browser Maps key is separate from the server credential ───────────────────────────
{
  const bad: string[] = [];
  const original = process.env.NEXT_PUBLIC_GOOGLE_MAPS_API_KEY;

  delete process.env.NEXT_PUBLIC_GOOGLE_MAPS_API_KEY;
  if (googleMapsBrowserKey() !== null) bad.push("unset key did not read as null");

  process.env.NEXT_PUBLIC_GOOGLE_MAPS_API_KEY = "   ";
  if (googleMapsBrowserKey() !== null) bad.push("blank key did not read as null");

  process.env.NEXT_PUBLIC_GOOGLE_MAPS_API_KEY = "browser-key-placeholder";
  if (googleMapsBrowserKey() !== "browser-key-placeholder") bad.push("configured key not returned");

  if (original === undefined) delete process.env.NEXT_PUBLIC_GOOGLE_MAPS_API_KEY;
  else process.env.NEXT_PUBLIC_GOOGLE_MAPS_API_KEY = original;

  // THE CREDENTIAL BOUNDARY, asserted against the source itself: the map layer must reach only for
  // the public browser variable, never for the server-side discovery credential or its storage.
  const source = readFileSync(
    new URL("../../src/modules/crm/features/nearby-clients/lib/map-provider.ts", import.meta.url),
    "utf8",
  );
  for (const forbidden of ["AIza", "api_key", "apiKey", "NerbyProviderSetting", "places_key"]) {
    if (source.includes(forbidden)) bad.push(`map layer references "${forbidden}"`);
  }
  if (!source.includes("NEXT_PUBLIC_GOOGLE_MAPS_API_KEY")) {
    bad.push("map layer does not read the public browser key");
  }

  report("G  the browser Maps key is its own credential", 9 - bad.length, 9, bad);
}

// ── H  the Found By filter can never choose the map ──────────────────────────────────────────
//
// TWO AXES THAT MUST NOT TOUCH. "Found By" decides WHICH RECORDS are listed; the effective discovery
// provider decides WHICH MAP renders them. Coupling them would mean filtering to one lane silently
// swapped the map — or, worse, that selecting "Google Places" in a filter demanded a browser Maps
// key. This is asserted structurally, because a behavioural test cannot prove the absence of an input.
{
  const bad: string[] = [];

  // The decision takes exactly ONE argument: a filter value has nowhere to enter.
  if (mapProviderFor.length !== 1) bad.push(`mapProviderFor takes ${mapProviderFor.length} args, want 1`);

  // The same provider yields the same map no matter what else is happening on the board.
  const repeated = new Set(["openstreetmap", "openstreetmap", "openstreetmap"].map(mapProviderFor));
  if (repeated.size !== 1) bad.push("mapProviderFor is not a pure function of the provider");

  const files = {
    "map-provider.ts": "../../src/modules/crm/features/nearby-clients/lib/map-provider.ts",
    "nearby-map.tsx": "../../src/modules/crm/features/nearby-clients/components/nearby-map.tsx",
    "use-map-provider.ts": "../../src/modules/crm/features/nearby-clients/hooks/use-map-provider.ts",
  };
  for (const [name, path] of Object.entries(files)) {
    const source = readFileSync(new URL(path, import.meta.url), "utf8");
    // Comments legitimately discuss the distinction, so only CODE references are refused.
    const code = source
      .split("\n")
      .filter((line) => !line.trimStart().startsWith("*") && !line.trimStart().startsWith("//"))
      .join("\n");
    for (const forbidden of ["foundBy", "NearbyFilters", "filters."]) {
      if (code.includes(forbidden)) bad.push(`${name} references "${forbidden}" in code`);
    }
  }

  report("H  the Found By filter cannot choose the map", 11 - bad.length, 11, bad);
}

// ── I  an empty list says WHY, and only when it can prove it ─────────────────────────────────
//
// Zero cards has several causes that look identical. The priority explanation is the one that must
// never be guessed: claiming "no High yet" when the pool is empty, or when another filter did the
// excluding, would send a BD to the wrong control.
{
  const base = { visibleCount: 0, poolIsEmpty: false, priorityFilter: "High", matchesIgnoringPriority: 5 };
  const cases: [string, Parameters<typeof nearbyEmptyReason>[0], string | null][] = [
    ["rows present", { ...base, visibleCount: 3 }, null],
    ["priority alone excluded everything", base, "priority-excluded-all"],
    // An empty pool outranks every filter — nothing was discovered, so no filter is to blame.
    ["pool empty, priority selected", { ...base, poolIsEmpty: true }, "pool-empty"],
    // Dropping priority would ALSO have shown nothing, so priority was not the cause.
    ["other filters excluded everything", { ...base, matchesIgnoringPriority: 0 }, "filters-excluded-all"],
    ["no priority selected", { ...base, priorityFilter: "", matchesIgnoringPriority: 0 }, "filters-excluded-all"],
  ];
  const bad = cases
    .filter(([, input, want]) => nearbyEmptyReason(input) !== want)
    .map(([label, input, want]) => `${label} → ${nearbyEmptyReason(input)}, want ${want}`);

  // The sentence states the mechanism and never claims discovery failed.
  const msg = nearbyEmptyMessage("priority-excluded-all", "High");
  if (!msg.includes("High")) bad.push("priority message does not name the selected priority");
  if (!/Website Intelligence/i.test(msg)) bad.push("priority message does not explain the mechanism");
  for (const forbidden of ["failed", "error", "no results found"]) {
    if (msg.toLowerCase().includes(forbidden)) bad.push(`priority message implies failure ("${forbidden}")`);
  }
  // The pre-existing states must keep their exact wording.
  if (!nearbyEmptyMessage("pool-empty", "").includes("No discovered businesses yet")) {
    bad.push("pool-empty message changed");
  }
  if (nearbyEmptyMessage("filters-excluded-all", "") !== "No clients match these filters.") {
    bad.push("filters-excluded-all message changed");
  }

  report("I  an empty list explains itself only when provable", cases.length + 6 - bad.length, cases.length + 6, bad);
}

// ── J  a map fallback is not a provider fallback ─────────────────────────────────────────────
//
// When Google Places is effective but its map cannot be drawn, the board renders the OpenStreetMap
// map with the SAME Google records. The distinction this gate protects: the renderer degrades, the
// discovery provider does not — `mapProviderFor` must keep saying "google" in exactly the cases
// where `mapRendererFor` has fallen back to OSM tiles.
{
  const cases: [string, Parameters<typeof mapRendererFor>[0], string][] = [
    ["google + key present", { effectiveProvider: "google_places", hasBrowserKey: true }, "google"],
    ["google + NO key", { effectiveProvider: "google_places", hasBrowserKey: false }, "openstreetmap"],
    ["google + key but script failed", { effectiveProvider: "google_places", hasBrowserKey: true, googleFailed: true }, "openstreetmap"],
    ["osm provider", { effectiveProvider: "openstreetmap", hasBrowserKey: true }, "openstreetmap"],
    ["ai-assisted osm provider", { effectiveProvider: "openai", hasBrowserKey: true }, "openstreetmap"],
    ["unknown provider", { effectiveProvider: "some_future_provider", hasBrowserKey: true }, "openstreetmap"],
    ["lookup not returned", { effectiveProvider: undefined, hasBrowserKey: true }, "openstreetmap"],
    // A key cannot conjure Google's map for a provider that is not Google.
    ["osm provider + key present", { effectiveProvider: "openstreetmap", hasBrowserKey: true, googleFailed: false }, "openstreetmap"],
  ];
  const bad = cases
    .filter(([, input, want]) => mapRendererFor(input) !== want)
    .map(([label, input, want]) => `${label} → ${mapRendererFor(input)}, want ${want}`);

  // THE PROPERTY THAT MATTERS: in both fallback cases the DATA provider is still Google.
  for (const input of [
    { effectiveProvider: "google_places", hasBrowserKey: false },
    { effectiveProvider: "google_places", hasBrowserKey: true, googleFailed: true },
  ] as const) {
    if (mapProviderFor(input.effectiveProvider) !== "google") {
      bad.push("map fallback changed the discovery provider");
    }
    if (mapRendererFor(input) !== "openstreetmap") {
      bad.push("map did not fall back to OpenStreetMap");
    }
  }

  report("J  a map fallback is not a provider fallback", cases.length + 4 - bad.length, cases.length + 4, bad);
}

// ── K  the country is a reserved word, not a blocklist ───────────────────────────────────────
{
  const bad: string[] = [];

  // Every spelling the backend reserves must be recognised here too, or the UI lets a user spend a
  // call on a request the server will refuse.
  for (const name of [
    "Philippines",
    "philippines",
    "  The Philippines  ",
    "REPUBLIC OF THE PHILIPPINES",
    "ph",
    "PHL",
    "Pilipinas",
  ]) {
    if (!isCountryName(name)) bad.push(`${JSON.stringify(name)} → not recognised as the country`);
  }

  // THE PROPERTY THAT MATTERS: this is one reserved word, not the beginning of a location whitelist.
  // A city, a province, a barangay and a place that contains the country's name as a substring all
  // stay free text.
  for (const name of [
    "Cebu City",
    "Roxas City",
    "Bacolod City, Philippines",
    "Philippine Arena",
    "New Philippines Bakery",
    "Negros Occidental",
  ]) {
    if (isCountryName(name)) bad.push(`${JSON.stringify(name)} → wrongly treated as the country`);
  }

  report("K  the country is reserved, every other place is free text", 13 - bad.length, 13, bad);
}

// ── L  the country is a real, defaulted, Philippines-only setting ────────────────────────────
{
  const bad: string[] = [];

  // Exactly one supported country today, and it is the Philippines.
  if (SUPPORTED_COUNTRIES.length !== 1) {
    bad.push(`${SUPPORTED_COUNTRIES.length} countries offered; only the Philippines is supported`);
  }
  if (SUPPORTED_COUNTRIES[0]?.code !== "PH") {
    bad.push(`supported country is ${SUPPORTED_COUNTRIES[0]?.code}, want PH`);
  }

  // THE DEFAULT IS THE SUPPORTED ONE. A default that is not in the list would render a form whose
  // initial value the server would refuse — a dead control that looks live.
  if (DEFAULT_COUNTRY !== "PH") bad.push(`default country is ${DEFAULT_COUNTRY}, want PH`);
  if (!SUPPORTED_COUNTRIES.some((c) => c.code === DEFAULT_COUNTRY)) {
    bad.push("the default country is not one of the supported countries");
  }

  // Labelled for display, and an unknown code renders as itself rather than being invented.
  if (countryLabel("PH") !== "Philippines") bad.push(`PH labelled "${countryLabel("PH")}"`);
  if (countryLabel("MY") !== "MY") bad.push("an unsupported country code was given a made-up name");

  // Island groups are labelled, never guessed: an id with no label renders as itself.
  if (islandGroupLabel("luzon") !== "Luzon") bad.push("luzon lost its label");
  if (islandGroupLabel("palawan") !== "palawan") bad.push("an unknown island group was invented");
  if (islandGroupLabel(null) !== "—") bad.push("a missing island group became a name");

  report("L  the country is Philippines-only and defaulted", 9 - bad.length, 9, bad);
}

// ── N  on-demand discovery appears only where the capability is real ─────────────────────────
{
  const bad: string[] = [];

  // Google Places is a factual provider and backs both surfaces.
  if (!canRunDiscovery("google_places")) bad.push("google_places must allow on-demand discovery");
  if (!canRunDiscovery("openai")) bad.push("openai must allow on-demand discovery");

  // THE DEFAULT EXPERIENCE IS UNTOUCHED. On the OpenStreetMap baseline none of the Stage 2 UI
  // appears — the board is what it was before on-demand discovery existed.
  if (canRunDiscovery("openstreetmap")) bad.push("the OSM baseline must not expose discovery controls");

  // NATIONWIDE IS THE SERVER'S ANSWER, NOT A PROVIDER COMPARISON DONE HERE. The frontend used to
  // decide this from the provider id, which is precisely how it went stale: the server replaced its
  // Google-only refusal with a budget fence and this copy kept refusing. The gate now checks that
  // the capability is READ rather than re-derived.
  if (!canRunNationwide({ available: true, maxTasks: 400, reason: "default_budget" })) {
    bad.push("an available nationwide capability must be offered");
  }
  if (!canRunNationwide({ available: true, maxTasks: 60, reason: "overpass_fence" })) {
    bad.push("a fenced but available nationwide capability must still be offered");
  }
  // THE BASELINE'S SHAPE EXACTLY: fenced like the AI lane, but not offered. A budget alone is never
  // permission — reading `maxTasks` as the flag is how the control would reappear on the baseline.
  if (canRunNationwide({ available: false, maxTasks: 60, reason: "baseline_fills_on_a_schedule" })) {
    bad.push("the OSM baseline must not expose nationwide despite having a budget");
  }
  // A budget of zero cannot start a run, so it is not permission however `available` reads.
  if (canRunNationwide({ available: true, maxTasks: 0, reason: "default_budget" })) {
    bad.push("a zero budget was read as nationwide permission");
  }

  // FAIL CLOSED WHILE UNKNOWN. A lookup that has not answered, or failed, is not permission — and
  // a provider id this build does not know is never assumed to be capable.
  for (const unknown of [null, undefined, "", "some_future_provider"]) {
    if (canRunDiscovery(unknown)) bad.push(`${JSON.stringify(unknown)} was read as permission`);
  }
  // A backend older than the nationwide block sends no block at all: absent is never permission.
  for (const missing of [null, undefined]) {
    if (canRunNationwide(missing)) bad.push(`${JSON.stringify(missing)} was read as nationwide permission`);
  }

  // Available means no reason to explain; unavailable always explains itself, and never by naming
  // a credential — the reason is shown to every BD.
  if (discoveryUnavailableReason("google_places") !== null) bad.push("an available capability must give no reason");
  const osmReason = discoveryUnavailableReason("openstreetmap") ?? "";
  if (osmReason === "") bad.push("an unavailable capability must explain itself");
  if (/key|token|secret|credential/i.test(osmReason)) bad.push(`the reason names a credential: ${osmReason}`);

  report("N  discovery controls follow the real capability", 16 - bad.length, 16, bad);
}

// ── O  every rendered list is keyed by what actually identifies a row ─────────────────────────
//
// A REAL CONSOLE ERROR, NOT A STYLE RULE. The per-area summary was keyed on the area label while the
// server builds that list with `groupBy('area', 'island_group')`. The two derivations are
// independent — a cell takes its label from the nearest anchor city and its group from the
// major-landmass boxes — so one label can legitimately span two groups, and React then saw two
// children with the same key and dropped one from reconciliation.
//
// Pinned by reading the source rather than by rendering: `frontend/` has no DOM test runner, and the
// property is textual anyway. Any future list added here must key on something that can repeat only
// when the rows are genuinely the same row.
{
  const bad: string[] = [];
  const dialog = readFileSync(
    new URL("../../src/modules/crm/features/nearby-clients/components/advanced-discovery-dialog.tsx", import.meta.url),
    "utf8",
  );

  // The exact collision that was reported: General Santos appeared under both mindanao and visayas.
  if (/key=\{a\.area\}/.test(dialog)) {
    bad.push("the per-area summary is keyed on the label alone; one label can span two island groups");
  }
  if (!/key=\{`\$\{a\.area\}\|\$\{a\.islandGroup\}`\}/.test(dialog)) {
    bad.push("the per-area key must mirror the server's groupBy(area, island_group)");
  }
  // The scoped list beside it has the same shape of risk and must keep its full natural key.
  if (!/key=\{`\$\{t\.area\}\|\$\{t\.category\}`\}/.test(dialog)) {
    bad.push("the per-target key must mirror its (area, category) identity");
  }

  report("O  list keys mirror the identity that produced the row", 3 - bad.length, 3, bad);
}

// ── P  an unpayable credential is never shown as healthy ─────────────────────────────────────
//
// THE STATE THIS EXISTS FOR. OpenRouter answers 200 on its zero-cost `/key` probe for a key whose
// account holds no credit, so the settings page used to show "Verified" beside a credential that
// fails 402 on every real call — the most misleading thing this card can say. The server now reports
// `insufficient_credit`; these gates pin the client half, so the status cannot arrive and be rendered
// as a generic "Configured", or worse, as "Active".
//
// Pinned by reading the source for the same reason as gates G, H and O: `frontend/` has no DOM test
// runner, and the properties here are structural — which branch exists, and in what ORDER.
{
  const bad: string[] = [];
  const hook = readFileSync(
    new URL("../../src/modules/crm/features/settings/hooks/use-nerby-integration.ts", import.meta.url),
    "utf8",
  );
  const card = readFileSync(
    new URL("../../src/modules/crm/features/settings/components/nerby-integration-card.tsx", import.meta.url),
    "utf8",
  );

  // The status the server can now send must be a status the client's type admits, or it arrives as
  // an unhandled value and falls through to "Not tested".
  if (!/\|\s*"insufficient_credit"/.test(hook)) {
    bad.push("NerbyTestStatus does not include insufficient_credit");
  }
  if (!/case "insufficient_credit":/.test(hook)) {
    bad.push("testStatusLabel has no case for insufficient_credit");
  }
  // A person must read about CREDIT, not a raw code and not a network story.
  const label = hook.match(/case "insufficient_credit":\s*\n\s*return "([^"]+)"/);
  if (!label) bad.push("insufficient_credit has no plain-language label");
  else if (!/credit/i.test(label[1])) bad.push(`insufficient_credit label does not mention credit: "${label[1]}"`);
  else if (/insufficient_credit/.test(label[1])) bad.push("the label leaks the raw status code");

  // The card gives it a state of its own rather than letting it fall into "Configured".
  if (!card.includes('provider.lastTestStatus === "insufficient_credit"')) {
    bad.push("the settings card has no insufficient_credit branch");
  }
  if (!/blocked:\s*"/.test(card)) bad.push("the blocked tone has no chip class");

  // THE ORDERING IS THE POINT. A credential known to be unpayable must be judged BEFORE the
  // "is this the running provider" branch, or a selected-but-broke provider still reads "Active".
  const creditAt = card.indexOf('provider.lastTestStatus === "insufficient_credit"');
  const activeAt = card.indexOf("if (isRunning) {");
  if (creditAt === -1 || activeAt === -1) bad.push("could not locate both branches to compare order");
  else if (creditAt > activeAt) bad.push("insufficient_credit is checked after Active, so a broke provider still reads Active");

  // And the healthy path is untouched: "ok" still means Verified.
  if (!/provider\.lastTestStatus === "ok"/.test(card)) {
    bad.push("the Verified branch no longer keys on ok");
  }

  report("P  an unpayable credential is never shown as healthy", 8 - bad.length, 8, bad);
}

// ── AI DISCOVERY ─────────────────────────────────────────────────────────────────────────────

const scopedInput = {
  scope: "scoped" as const,
  locations: ["Bacolod City", "Iloilo City"],
  niches: ["vape shop"],
  limitPerTask: 20,
  country: "PH" as const,
  idempotencyKey: "k-1",
};
const nationwideInput = {
  scope: "nationwide" as const,
  niches: ["bakery"],
  country: "PH" as const,
  idempotencyKey: "k-2",
};

type AiRun = Pick<DiscoveryRun, "status" | "ai" | "isTerminal">;
const aiRun = (status: DiscoveryRun["status"], ai: DiscoveryRun["ai"], isTerminal = false): AiRun => ({
  status,
  ai,
  isTerminal,
});

// ── Q  AI discovery submission ───────────────────────────────────────────────────────────────
{
  const bad: string[] = [];
  const scoped = buildStartRunBody({ ...scopedInput, aiStrategy: true });
  const plainScoped = buildStartRunBody(scopedInput);
  if (scoped.ai_strategy !== true) bad.push("scoped AI body lacks ai_strategy: true");
  const { ai_strategy: _dropped, ...rest } = scoped;
  void _dropped;
  if (JSON.stringify(rest) !== JSON.stringify(plainScoped)) bad.push("AI body differs from the ordinary body beyond ai_strategy");
  // Free text travels exactly as typed — no niche whitelist, no rewriting.
  if (JSON.stringify(scoped.niches) !== JSON.stringify(["vape shop"])) bad.push("niche was rewritten");

  const nw = buildStartRunBody({ ...nationwideInput, aiStrategy: true });
  if (nw.ai_strategy !== true) bad.push("nationwide AI body lacks ai_strategy: true");
  if ("locations" in nw || "limit_per_task" in nw) bad.push("nationwide AI body carries locations/limit_per_task");

  report("Q  AI discovery submits the ordinary body plus ai_strategy", 5 - bad.length, 5, bad);
}

// ── R  the default request is byte-identical ─────────────────────────────────────────────────
{
  const bad: string[] = [];
  const scopedKeys = Object.keys(buildStartRunBody(scopedInput)).join(",");
  if (scopedKeys !== "scope,country,locations,niches,limit_per_task,idempotency_key") {
    bad.push(`scoped keys changed: ${scopedKeys}`);
  }
  // NATIONWIDE WITHOUT A SERVER BUDGET IS UNCHANGED. No `maxTasks` means no `max_tasks` key, so the
  // server keeps applying its own default exactly as it did before the capability block existed.
  const nwKeys = Object.keys(buildStartRunBody(nationwideInput)).join(",");
  if (nwKeys !== "scope,country,niches,idempotency_key") bad.push(`nationwide keys changed: ${nwKeys}`);
  // WITH one, it is sent verbatim — that is what makes the run one the orchestrator accepts rather
  // than one its fence refuses on arrival.
  const nwBudget = buildStartRunBody({ ...nationwideInput, maxTasks: 60 });
  if (nwBudget.max_tasks !== 60) bad.push("the server's nationwide budget is not sent as max_tasks");
  if (Object.keys(nwBudget).join(",") !== "scope,country,niches,idempotency_key,max_tasks") {
    bad.push(`nationwide budget keys changed: ${Object.keys(nwBudget).join(",")}`);
  }
  // THE SCOPED ARM NEVER GAINS IT. `limit_per_task` is how many businesses one search returns and
  // `max_tasks` is how many searches a run makes; the 20-per-search behaviour is untouched by this.
  if ("max_tasks" in buildStartRunBody(scopedInput)) bad.push("a scoped run sent max_tasks");
  if (buildStartRunBody(scopedInput).limit_per_task !== 20) bad.push("the scoped 20-per-search default changed");
  if ("ai_strategy" in buildStartRunBody({ ...scopedInput, aiStrategy: false })) {
    bad.push("aiStrategy: false sends an ai_strategy key");
  }
  report("R  an ordinary run's request is unchanged", 7 - bad.length, 7, bad);
}

// ── S  validation: the AI action is offered only when it can work ────────────────────────────
{
  const bad: string[] = [];
  const cases: [string | null | undefined, string | null | undefined, boolean][] = [
    ["google_places", "available", true],
    ["openstreetmap", "available", false],
    ["openai", "available", false],
    ["google_places", "insufficient_credit", false],
    ["google_places", "not_configured", false],
    ["google_places", "disabled", false],
    ["google_places", "unsupported_provider", false],
    ["google_places", undefined, false],
    ["google_places", "something_new", false],
    [null, null, false],
  ];
  for (const [provider, status, want] of cases) {
    if (canRunAiDiscovery(provider, status) !== want) bad.push(`${provider}/${status} → ${!want}, want ${want}`);
  }

  const dialog = readFileSync(
    new URL("../../src/modules/crm/features/nearby-clients/components/advanced-discovery-dialog.tsx", import.meta.url),
    "utf8",
  );
  // The AI submit goes through the SAME readiness check as the ordinary one, plus the capability.
  if (!dialog.includes("if (!canStart || (ai && !canRunAi)) return;")) {
    bad.push("the dialog does not guard an AI submit with canStart and canRunAi");
  }
  report("S  the AI action is offered and accepted only when available", cases.length + 1 - bad.length, cases.length + 1, bad);
}

// ── T  loading and running ───────────────────────────────────────────────────────────────────
{
  const bad: string[] = [];
  const requested = { requested: true, outcome: "not_applied" as const };
  if (discoveryPhase({ submitting: false, run: undefined }) !== "idle") bad.push("fresh form is not idle");
  if (discoveryPhase({ submitting: true, run: undefined }) !== "submitting") bad.push("in-flight start is not submitting");
  if (discoveryPhase({ submitting: false, run: aiRun("pending", requested) }) !== "queued") bad.push("pending is not queued");
  if (discoveryPhase({ submitting: false, run: aiRun("running", requested) }) !== "running") bad.push("running is not running");
  // While it runs, "not applied" means "not yet" — never a verdict. The engine is named either way.
  const live = aiOutcomeLabel(aiRun("running", requested));
  if (live !== "OpenAI — requested") bad.push(`a live AI run is labelled "${live}", want "OpenAI — requested"`);
  report("T  submitting, queued and running are distinct", 5 - bad.length, 5, bad);
}

// ── U  successful completion is described truthfully ─────────────────────────────────────────
{
  const bad: string[] = [];
  const applied = aiRun("completed", { requested: true, outcome: "applied" }, true);
  if (discoveryPhase({ submitting: false, run: applied }) !== "completed") bad.push("completed is not completed");
  // The AI row NAMES THE ENGINE. Google Places is the answer to the other row, and the message
  // below still has to say so — the two roles must never collapse back into one line.
  if (aiOutcomeLabel(applied) !== "OpenAI") bad.push("an applied run does not name OpenAI as the engine");
  if (!/Google Places/.test(aiOutcomeMessage(applied) ?? "")) bad.push("applied message does not say Google supplied the businesses");

  const unshaped = aiRun("completed", { requested: true, outcome: "not_applied" }, true);
  if (aiOutcomeLabel(unshaped) !== "OpenAI — not applied") bad.push("an AI run AI did not shape is not labelled OpenAI — not applied");
  if (!/did not change/.test(aiOutcomeMessage(unshaped) ?? "")) bad.push("the unshaped message does not say so");

  const ordinary = aiRun("completed", { requested: false, outcome: "not_applied" }, true);
  if (aiOutcomeLabel(ordinary) !== null || aiOutcomeMessage(ordinary) !== null) bad.push("an ordinary run shows an AI line");
  // A backend older than the field: no AI line, no crash.
  if (aiOutcomeLabel(aiRun("completed", undefined, true)) !== null) bad.push("a run with no ai field shows an AI line");
  report("U  completion reports what AI actually did", 7 - bad.length, 7, bad);
}

// ── V  cancellation ──────────────────────────────────────────────────────────────────────────
{
  const bad: string[] = [];
  const cancelled = aiRun("cancelled", { requested: true, outcome: "applied" }, true);
  if (discoveryPhase({ submitting: false, run: cancelled }) !== "cancelled") bad.push("cancelled is not cancelled");

  const hook = readFileSync(
    new URL("../../src/modules/crm/features/nearby-clients/hooks/use-discovery-runs.ts", import.meta.url),
    "utf8",
  );
  const dialog = readFileSync(
    new URL("../../src/modules/crm/features/nearby-clients/components/advanced-discovery-dialog.tsx", import.meta.url),
    "utf8",
  );
  if (!hook.includes("`/discovery/runs/${runId}/cancel`")) bad.push("the cancel hook does not call the existing endpoint");
  if (!dialog.includes("cancelRun.mutate(runId)")) bad.push("the dialog does not wire Cancel run");
  // Offered only while there is something to cancel.
  if (!dialog.includes("runId !== null && run !== undefined && !run.isTerminal")) bad.push("Cancel run is not limited to a live run");
  report("V  cancellation is wired and reported", 4 - bad.length, 4, bad);
}

// ── W  insufficient credit ───────────────────────────────────────────────────────────────────
{
  const bad: string[] = [];
  const stopped = aiRun("failed", { requested: true, outcome: "insufficient_credit" }, true);
  if (discoveryPhase({ submitting: false, run: stopped }) !== "insufficient_credit") bad.push("a credit-stopped run is not the credit state");
  if (aiOutcomeLabel(stopped) !== "OpenAI — no credit") bad.push("credit-stopped run not labelled OpenAI — no credit");
  if (!/no credit/.test(aiOutcomeMessage(stopped) ?? "")) bad.push("credit message does not name credit");
  if (discoveryPhase({ submitting: false, run: undefined, aiStartRefusedForCredit: true }) !== "insufficient_credit") {
    bad.push("a start refused for credit is not the credit state");
  }

  const refusal = { response: { status: 422, data: { message: "no credit", errors: { ai_strategy: ["x"] } } } };
  const otherInvalid = { response: { status: 422, data: { message: "bad", errors: { locations: ["x"] } } } };
  const conflict = { response: { status: 409, data: { message: "busy" } } };
  if (!isAiStartRefusal(refusal)) bad.push("a 422 on ai_strategy is not recognised");
  if (isAiStartRefusal(otherInvalid)) bad.push("a 422 on locations is read as an AI refusal");
  if (isAiStartRefusal(conflict) || isAiStartRefusal(undefined)) bad.push("a 409 / no error is read as an AI refusal");
  report("W  insufficient credit is its own, distinguishable state", 7 - bad.length, 7, bad);
}

// ── X  backend failure ───────────────────────────────────────────────────────────────────────
{
  const bad: string[] = [];
  const plainFail = aiRun("failed", { requested: false, outcome: "not_applied" }, true);
  const aiFail = aiRun("failed", { requested: true, outcome: "not_applied" }, true);
  if (discoveryPhase({ submitting: false, run: plainFail }) !== "failed") bad.push("an ordinary failure is not failed");
  if (discoveryPhase({ submitting: false, run: aiFail }) !== "failed") bad.push("an AI run's non-credit failure is relabelled");
  // A failed start that is not an AI refusal returns the form to idle, where the toast has said why.
  if (discoveryPhase({ submitting: false, run: undefined, aiStartRefusedForCredit: false }) !== "idle") {
    bad.push("a generic start failure is shown as a credit problem");
  }
  report("X  a backend failure stays a failure", 3 - bad.length, 3, bad);
}

// ── Y  no internal mechanics or credentials reach the UI ─────────────────────────────────────
{
  const bad: string[] = [];
  const sources = [
    "../../src/modules/crm/features/nearby-clients/lib/ai-discovery.ts",
    "../../src/modules/crm/features/nearby-clients/components/advanced-discovery-dialog.tsx",
    "../../src/modules/crm/features/nearby-clients/hooks/use-discovery-runs.ts",
  ].map((path) => [path, readFileSync(new URL(path, import.meta.url), "utf8")] as const);
  for (const [path, text] of sources) {
    for (const word of ["barren", "no_new", "productive", "abundant", "unmeasured", "api_key", "apiKey", "key_last4"]) {
      if (new RegExp(`\\b${word}\\b`).test(text)) bad.push(`${path.split("/").pop()} mentions ${word}`);
    }
  }
  report("Y  no feedback verdicts or credentials in the AI Discovery UI", 1 - Math.min(1, bad.length), 1, bad);
}

// ── Z  the existing / default discovery flow is unchanged ────────────────────────────────────
{
  const bad: string[] = [];
  const dialog = readFileSync(
    new URL("../../src/modules/crm/features/nearby-clients/components/advanced-discovery-dialog.tsx", import.meta.url),
    "utf8",
  );
  // Enter starts the ORDINARY run.
  const enter = dialog.match(/const submitOnEnter = [\s\S]*?\n {2}};/);
  if (!enter || !/\bsubmit\(\);/.test(enter[0])) bad.push("Enter no longer starts the ordinary run");
  // The primary button starts the ORDINARY run and keeps its label.
  if (!dialog.includes("<Button onClick={() => submit()} disabled={!canStart}>")) bad.push("Run discovery no longer calls submit() without AI");
  if (!dialog.includes('"Run nationwide discovery"') || !dialog.includes("`Run discovery${")) bad.push("the Run discovery labels changed");
  // The AI action is drawn only behind the capability.
  if (!dialog.includes("{runId === null && canRunAi && (")) bad.push("the AI button is not gated on canRunAi");
  // Nationwide stays Google-only, through the same capability as before.
  if (!dialog.includes('const nationwide = mode === "nationwide" && canRunNationwide;')) bad.push("nationwide gating changed");
  report("Z  the default Advanced Lead Discovery flow is unchanged", 5 - bad.length, 5, bad);
}

// ── AA  the AI engine and the factual source are named as two separate roles ─────────────────
//
// An AI-planned Google run is planned by OpenAI and sourced from Google Places at the same time.
// Before this, one row called "Provider used" carried both questions, so such a run read as purely
// Google-discovered. These assertions keep the two roles from collapsing back into one line — and
// keep the factual half pointing at the provider, never at the engine.
{
  const bad: string[] = [];
  const dialog = readFileSync(
    new URL("../../src/modules/crm/features/nearby-clients/components/advanced-discovery-dialog.tsx", import.meta.url),
    "utf8",
  );
  const types = readFileSync(
    new URL("../../src/modules/crm/features/nearby-clients/types/discovery-run-types.ts", import.meta.url),
    "utf8",
  );

  // Both roles are named, before a run and after it.
  if ((dialog.match(/Business data source/g) ?? []).length < 2) bad.push("the factual source is not named both before and after a run");
  if ((dialog.match(/AI engine/g) ?? []).length < 2) bad.push("the AI engine is not named both before and after a run");
  // The RENDERED row, not the word: the comment above it explains what it replaced and must stay.
  if (/>\s*Provider used\s*</.test(dialog)) bad.push("the old single-role 'Provider used' row is back");

  // The factual row reads the PROVIDER, never the engine name.
  if (!dialog.includes("{providerLabel(provider.effective)}")) bad.push("the run's factual row no longer reads provider.effective");
  // The lane label is "AI-assisted" (PO decision 2026-09-25): it covers both AI routes — expanded tag
  // sweeps and proposed names — and a names-only label mislabelled the tag-expansion leads.
  if (!/openai: "AI-assisted"/.test(types)) bad.push("the openai lane label is not 'AI-assisted'");
  if (/openai: "[^"]*OpenStreetMap"/.test(types)) bad.push("the openai lane label names OSM again, colliding with the OSM lane");

  // The engine name is one constant, not a word retyped per surface.
  if (!dialog.includes("AI_ENGINE_NAME")) bad.push("the dialog hard-codes the engine name instead of using AI_ENGINE_NAME");

  // Naming the engine must not have moved provenance: no surface may map a lead source to OpenAI.
  if (/google_places:\s*"[^"]*(OpenAI|AI-assisted)/.test(types)) bad.push("Google Places is labelled as an AI source");

  report("AA the AI engine and the factual source are named separately", 8 - bad.length, 8, bad);
}

// ── AB  the board names provenance, and never invents it ─────────────────────────────────────
//
// The Nearby board mixes three discovery lanes and used to show none of them. It now names each on
// the row — and, just as importantly, refuses to name one it cannot know: a Google lead carries no
// per-lead record of whether AI shaped the search that found it, so there is no "AI-assisted · Google
// Places". These assertions keep both halves of that: the lanes we CAN name stay named, and the one
// we cannot stays unclaimed.
{
  const bad: string[] = [];
  const hook = readFileSync(
    new URL("../../src/modules/crm/features/nearby-clients/hooks/use-nearby-clients.ts", import.meta.url),
    "utf8",
  );
  const card = readFileSync(
    new URL("../../src/modules/crm/features/nearby-clients/components/client-range-card.tsx", import.meta.url),
    "utf8",
  );

  // ONE VOCABULARY. The board and the run panel must not name the same lane two ways.
  if (!/"ai-assisted": "AI-assisted",/.test(hook)) bad.push("the board does not label the AI lane 'AI-assisted'");
  if (!/"ai-assisted": "AI-assisted",/.test(card)) bad.push("the card and the board name the AI lane differently");
  if (/AI-proposed names/.test(hook + card)) bad.push("the narrower names-only label is back on the board");
  // NEVER CROSS-POPULATE: the lane filter matches labels exactly, so every lane needs its own label.
  const order = hook.match(/const FOUND_BY_ORDER = \[([\s\S]*?)\] as const/)?.[1] ?? "";
  const labels = [...order.matchAll(/"([^"]+)"/g)].map((m) => m[1]);
  if (labels.length !== new Set(labels).size) bad.push("two Found By lanes share a label, so one filter would select both");
  const want = ["OpenStreetMap", "AI-assisted", "Google Places", "AI-assisted · Google Places"];
  if (JSON.stringify(labels) !== JSON.stringify(want)) bad.push(`the Found By options are not exactly ${want.join(" / ")}`);
  // MULTI-PROVENANCE (change set D): the filter reads EVERY lane that found a lead, so one lead found
  // by OpenStreetMap and the AI lane appears under both — as one row each time, never two leads.
  if (!/\(c\.foundByAll \?\? \[c\.foundBy\]\)\.some\(/.test(hook)) bad.push("the Found By filter does not read foundByAll");
  if ((hook.match(/matchesFoundBy\(c, filters\.foundBy\)/g) ?? []).length !== 2) bad.push("both Found By filter paths must use matchesFoundBy");
  // The dual chip is earned only by exactly OpenStreetMap + AI-assisted; Google chips never change.
  if (!/"OpenStreetMap · also AI-assisted"/.test(card)) bad.push("the dual-provenance chip text changed");
  if (!/client\.foundBy === "openstreetmap" && client\.foundByAll\?\.includes\("ai-assisted"\)/.test(card)) bad.push("the dual chip is not limited to OpenStreetMap + AI-assisted");
  if (!/"google-places": "Google Places",\s*"ai-assisted-google-places": "AI-assisted · Google Places",/.test(card)) bad.push("a Google chip label changed");

  // EARNED, NEVER INVENTED. "AI-assisted · Google Places" may exist ONLY as the label of the
  // `ai-assisted-google-places` lane — the one the server resolves from a per-lead
  // `discovery_ai_assisted === true`. Any OTHER key carrying that label would be attribution the
  // data does not support, which is exactly what this gate existed to forbid before the per-lead
  // fact was recorded.
  //
  // Tested against CODE ONLY, with comments stripped: the documentation explaining how the label is
  // earned has to be able to name it, and earlier drafts of this gate failed on their own prose.
  const code = (text: string): string =>
    text.replace(/\/\*[\s\S]*?\*\//g, "").replace(/^\s*\/\/.*$/gm, "");
  const googleAiLabel = /(["'])([^"']*)\1:\s*(["'])AI-assisted · Google Places\3/g;
  const misattributed = (text: string): boolean => {
    for (const [, , key] of text.matchAll(googleAiLabel)) {
      if (key !== "ai-assisted-google-places") return true;
    }
    return false;
  };
  if (misattributed(code(hook))) bad.push("the board attaches the Google AI label to a lane that did not earn it");
  if (misattributed(code(card))) bad.push("the card attaches the Google AI label to a lane that did not earn it");

  // THE LABEL MUST STILL BE REACHABLE, or the feature is silently dead.
  if (!/"ai-assisted-google-places": "AI-assisted · Google Places"/.test(hook)) bad.push("the board can no longer name an AI-assisted Google lead");
  if (!/"ai-assisted-google-places": "AI-assisted · Google Places"/.test(card)) bad.push("the card can no longer name an AI-assisted Google lead");

  // NEVER FROM THE RUN. `ai_outcome` latches to `applied` for a whole run and cannot answer a
  // per-lead question; no board surface may read it.
  if (/ai_?[Oo]utcome/.test(code(hook))) bad.push("the board derives lead provenance from the run's ai_outcome");
  if (/ai_?[Oo]utcome/.test(code(card))) bad.push("the card derives lead provenance from the run's ai_outcome");

  // Provenance is shown on the row, from data the payload already carries.
  if (!/FOUND_BY_CHIP/.test(card)) bad.push("the card no longer shows where the business came from");
  if (!/client\.foundBy/.test(card)) bad.push("the card's provenance chip no longer reads foundBy");

  // PROVENANCE IS ADDITIVE. The chip was added BESIDE the priority chip, and the priority vocabulary
  // is deliberately untouched: High / Medium / Low stays the name of the derived prospecting
  // priority on every surface. An earlier draft renamed `high` to "Website opportunity" on the card
  // and in the intel panel; that conflated a descriptive signal with the priority label and was
  // reverted. These assertions keep the priority labels intact on both surfaces that render them.
  const panel = readFileSync(
    new URL("../../src/modules/crm/features/nearby-clients/components/website-intel-panel.tsx", import.meta.url),
    "utf8",
  );
  if (!/text: "High priority"/.test(card)) bad.push("the card no longer labels the derived priority 'High priority'");
  if (!/label: "High priority"/.test(panel)) bad.push("the intel panel no longer labels the derived priority 'High priority'");

  // The FILTER vocabulary is what a selected value is COMPARED against by formatPriority(), so a
  // renamed option would silently match nothing. This guards it staying put.
  if (!/PRIORITY_ORDER = \["High", "Medium", "Low"\]/.test(hook)) bad.push("the priority filter vocabulary changed, which silently breaks filtering");

  report("AB the board names provenance and never invents it", 21 - bad.length, 21, bad);
}


// AC — THE SPEND LIMIT IS A CONTROL, NOT A CAPTION.
//
// The budget is enforced server-side on every Google Places request; this gate pins the client half,
// where the ways it goes wrong are all silent:
//
//   - an amount hardcoded in the UI, so the "configurable" promise is a lie
//   - a save that sends only the changed key, which the server treats as the WHOLE options bag and
//     so quietly clears this provider's pricing overrides
//   - dollars shown without requests, which hides a stale price inside the number it distorts
//   - "no limit" and "zero" rendered the same, when they mean opposite things
//   - a second endpoint or a second meter growing beside the one that exists
{
  const bad: string[] = [];
  const hook = readFileSync(
    new URL("../../src/modules/crm/features/settings/hooks/use-nerby-integration.ts", import.meta.url),
    "utf8",
  );
  const card = readFileSync(
    new URL("../../src/modules/crm/features/settings/components/nerby-integration-card.tsx", import.meta.url),
    "utf8",
  );

  // The server's shape must be a shape the client admits, or the meter renders as blanks.
  if (!/export type NerbySpendState/.test(hook)) bad.push("NerbySpendState is not declared");
  for (const field of ["limitUsd", "billedUsd", "remainingUsd", "requestsUsed", "requestsAllowed", "unlimited"]) {
    if (!new RegExp(`\\b${field}\\b`).test(hook)) bad.push(`NerbySpendState is missing ${field}`);
  }
  if (!/spend: NerbySpendState \| null/.test(hook)) bad.push("NerbyProviderConfig does not carry spend");

  // NO AMOUNT IS BAKED INTO THE UI. The field's default comes from what the server stored, never
  // from a literal — $10 is a configured value, not an application constant.
  if (/defaultValues:\s*\{\s*spendLimitUsd:\s*"[0-9]/.test(card)) {
    bad.push("the spend field defaults to a hardcoded amount");
  }
  if (!/spendLimitUsd:\s*\(provider\.options\?\.spend_limit_usd/.test(card)) {
    bad.push("the spend field does not default from the stored configuration");
  }

  // PROVIDER-DRIVEN, not provider-named: the panel appears because the server says this provider
  // takes the option, exactly as the model field does.
  if (!/provider\.optionKeys\.includes\("spend_limit_usd"\)/.test(card)) {
    bad.push("the spend panel is not gated on the provider's declared option keys");
  }

  // THE WHOLE BAG TRAVELS. The server REPLACES options with what it receives, so a save that sent
  // only the limit would silently drop price_per_1000_usd / free_tier_requests / safety_factor.
  if (!/\.\.\.currentOptions\(provider\),\s*spend_limit_usd:/.test(card)) {
    bad.push("saving the limit does not resend the provider's other options, so they would be cleared");
  }

  // ONE ENDPOINT, ONE METER. The panel goes through the existing configure mutation; it must not
  // grow its own API call or its own client-side tally.
  if (/apiClient\./.test(card)) bad.push("the settings card calls the API directly instead of using the existing hook");
  if (!/configure\.mutate\(\{\s*\n?\s*provider: provider\.provider,\s*\n?\s*\/\//.test(card) && !/configure\.mutate\(/.test(card)) {
    bad.push("the spend panel does not use the existing configure mutation");
  }

  // BOTH UNITS. Dollars are a model; requests are what is counted. Showing one without the other is
  // how a wrong price constant stays invisible.
  if (!/spend\.requestsAllowed/.test(card)) bad.push("the meter never shows the request allowance");
  if (!/spend\.billedUsd/.test(card)) bad.push("the meter never shows what has been charged");
  if (!/spend\.remainingUsd/.test(card)) bad.push("the meter never shows the remaining budget");

  // THE THREE STATES ARE DISTINGUISHABLE. "No limit" and "Stopped" look alike and mean opposites.
  if (!/spend\.unlimited/.test(card)) bad.push("the card has no branch for an unconfigured limit");
  if (!/spend\.limitUsd === 0/.test(card)) bad.push("the card has no branch for a zero (stopped) limit");
  if (!/spend\.unreadable/.test(card)) bad.push("the card has no branch for an unreadable stored limit");

  // The copy must say the baseline is unaffected: an admin setting a Google budget needs to know
  // OpenStreetMap keeps running, or a zero will read as switching discovery off entirely.
  if (!/OpenStreetMap/.test(card)) bad.push("the spend copy does not say OpenStreetMap is unaffected");

  report("AC the spend limit is configurable, enforced and legible", 18 - bad.length, 18, bad);
}

console.log(failed === 0 ? "\nAll gates passed." : `\n${failed} gate(s) FAILED.`);
process.exit(failed === 0 ? 0 : 1);
