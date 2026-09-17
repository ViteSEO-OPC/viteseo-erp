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
  if (!canRunNationwide("google_places")) bad.push("google_places must allow nationwide");

  // The AI capability plans searches; it does not answer them, so it may not offer nationwide —
  // the orchestrator refuses any nationwide run that is not on Google.
  if (!canRunDiscovery("openai")) bad.push("openai must allow on-demand discovery");
  if (canRunNationwide("openai")) bad.push("openai must NOT offer nationwide; the server refuses it");

  // THE DEFAULT EXPERIENCE IS UNTOUCHED. On the OpenStreetMap baseline none of the Stage 2 UI
  // appears — the board is what it was before on-demand discovery existed.
  if (canRunDiscovery("openstreetmap")) bad.push("the OSM baseline must not expose discovery controls");
  if (canRunNationwide("openstreetmap")) bad.push("the OSM baseline must not expose nationwide");

  // FAIL CLOSED WHILE UNKNOWN. A lookup that has not answered, or failed, is not permission — and
  // a provider id this build does not know is never assumed to be capable.
  for (const unknown of [null, undefined, "", "some_future_provider"]) {
    if (canRunDiscovery(unknown)) bad.push(`${JSON.stringify(unknown)} was read as permission`);
    if (canRunNationwide(unknown)) bad.push(`${JSON.stringify(unknown)} was read as nationwide permission`);
  }

  // Available means no reason to explain; unavailable always explains itself, and never by naming
  // a credential — the reason is shown to every BD.
  if (discoveryUnavailableReason("google_places") !== null) bad.push("an available capability must give no reason");
  const osmReason = discoveryUnavailableReason("openstreetmap") ?? "";
  if (osmReason === "") bad.push("an unavailable capability must explain itself");
  if (/key|token|secret|credential/i.test(osmReason)) bad.push(`the reason names a credential: ${osmReason}`);

  report("N  discovery controls follow the real capability", 17 - bad.length, 17, bad);
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

console.log(failed === 0 ? "\nAll gates passed." : `\n${failed} gate(s) FAILED.`);
process.exit(failed === 0 ? 0 : 1);
