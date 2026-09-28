"use client";

import { useMemo, useState } from "react";
import { Globe2, Loader2, MapPin, Search, Sparkles } from "lucide-react";
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from "@/modules/crm/components/ui/dialog";
import { Button } from "@/modules/crm/components/ui/button";
import { Input } from "@/modules/crm/components/ui/input";
import { cn } from "@/modules/crm/lib/utils";
// The only primitive with no CRM-tree equivalent; this is the path the rest of the app uses too.
import { Label } from "@/components/ui/label";
import {
  Progress,
  ProgressTrack,
  ProgressIndicator,
} from "@/modules/crm/components/ui/progress";
import { AlertBanner } from "@/modules/crm/components/shared/alert-banner/alert-banner";
import {
  useCancelDiscoveryRun,
  useDiscoveryRun,
  useStartDiscoveryRun,
} from "@/modules/crm/features/nearby-clients/hooks/use-discovery-runs";
import {
  AI_ENGINE_NAME,
  aiOutcomeLabel,
  aiOutcomeMessage,
  discoveryPhase,
  isAiStartRefusal,
} from "@/modules/crm/features/nearby-clients/lib/ai-discovery";
import { useDiscoveryCapability } from "@/modules/crm/features/nearby-clients/hooks/use-map-provider";
import { Badge } from "@/modules/crm/components/ui/badge";
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/modules/crm/components/ui/select";
import {
  DEFAULT_COUNTRY,
  MAX_LIMIT_PER_TASK,
  MAX_TASKS_PER_RUN,
  SUPPORTED_COUNTRIES,
  countryLabel,
  islandGroupLabel,
  isCountryName,
  providerLabel,
  splitFreeText,
  type CountryCode,
  type DiscoveryRun,
  type DiscoveryRunScope,
} from "@/modules/crm/features/nearby-clients/types/discovery-run-types";

type Props = {
  open: boolean;
  onOpenChange: (open: boolean) => void;
};

/**
 * ADVANCED LEAD DISCOVERY — the Stage 2 on-demand run.
 *
 * WHAT THIS IS NOT. It is not the Nearby Clients board and it does not replace it. The board shows
 * the prospect POOL that already exists and is filtered entirely on the client; this dialog asks a
 * factual provider for businesses that are not in that pool yet. Closing it leaves the board exactly
 * as it was, and every filter on the board remains a presentation control that starts nothing.
 *
 * FREE TEXT ON BOTH AXES. Locations and niches are typed, not chosen from a list, because there is
 * no city whitelist and no niche whitelist anywhere in this product. "bakery in Bacolod City" and
 * "vape shop in Roxas City" are equally valid inputs; the factual provider owns that vocabulary.
 *
 * PROVIDER TRUTH IS SHOWN, NOT IMPLIED. While a run executes, the panel names the provider that is
 * actually answering. If the run falls back, it says so in words — results found by OpenStreetMap
 * are never presented as Google Places results.
 */
export function AdvancedDiscoveryDialog({ open, onOpenChange }: Props) {
  const [mode, setMode] = useState<DiscoveryRunScope>("scoped");
  const [country, setCountry] = useState<CountryCode>(DEFAULT_COUNTRY);
  const [locationsText, setLocationsText] = useState("");
  const [nichesText, setNichesText] = useState("");
  // The scoped default, unchanged and deliberately NOT shared with nationwide: a nationwide run's
  // per-cell limit is server configuration, and this input is not shown for it.
  const [limitPerTask, setLimitPerTask] = useState(20);
  const [runId, setRunId] = useState<string | null>(null);
  // Which action started the request in flight, so its spinner lands on the button that was pressed.
  const [aiSubmitted, setAiSubmitted] = useState(false);

  const start = useStartDiscoveryRun();
  const cancelRun = useCancelDiscoveryRun();
  const { data: run } = useDiscoveryRun(runId);
  // Nationwide is Google-only, exactly as the orchestrator requires. Offering it on any other
  // provider could only ever produce a run that fails the moment the worker picks it up.
  // AI Discovery is offered only when the backend reports AI available on the Google flow.
  const { canRunNationwide, canRunAi, aiStatus, effectiveProvider } = useDiscoveryCapability();
  const phase = discoveryPhase({
    submitting: start.isPending,
    run,
    aiStartRefusedForCredit: isAiStartRefusal(start.error) && aiStatus === "insufficient_credit",
  });

  const nationwide = mode === "nationwide" && canRunNationwide;
  const locations = useMemo(() => splitFreeText(locationsText), [locationsText]);
  const niches = useMemo(() => splitFreeText(nichesText), [nichesText]);
  const searchCount = locations.length * niches.length;

  // The reserved word. Caught here so the user is pointed at the mode that answers it properly,
  // before anything is spent — the backend refuses the same input independently.
  const countryTyped = useMemo(() => locations.filter(isCountryName), [locations]);

  const tooManyTasks = searchCount > MAX_TASKS_PER_RUN;
  const limitValid = Number.isFinite(limitPerTask) && limitPerTask >= 1 && limitPerTask <= MAX_LIMIT_PER_TASK;
  const scopedReady = searchCount > 0 && !tooManyTasks && limitValid && countryTyped.length === 0;
  // A nationwide run needs nothing but a niche: its coverage is the country.
  const nationwideReady = niches.length > 0;
  // `start.isPending` is what prevents a double submission: the button is disabled from the moment
  // the request leaves until the run id comes back.
  const canStart =
    (nationwide ? nationwideReady : scopedReady) && !start.isPending && runId === null;

  /**
   * Start a run. `ai` is set only by the "Discover with AI" action; Enter and "Run discovery" start
   * the ordinary run exactly as before, and its request carries no `ai_strategy` key at all.
   */
  const submit = (ai = false) => {
    if (!canStart || (ai && !canRunAi)) return;
    // Stable for this dialog session, so a double-click or a retried request resolves to the same
    // run rather than buying a second one.
    const idempotencyKey = `nearby-${Date.now()}-${Math.random().toString(36).slice(2, 10)}`;
    const aiStrategy = ai ? { aiStrategy: true } : {};
    setAiSubmitted(ai);

    start.mutate(
      nationwide
        ? { scope: "nationwide", niches, country, idempotencyKey, ...aiStrategy }
        : { scope: "scoped", locations, niches, limitPerTask, country, idempotencyKey, ...aiStrategy },
      { onSuccess: (created) => setRunId(created.runId) },
    );
  };

  /** Stop watching a run and return to the form. Touches nothing on the server. */
  /**
   * ENTER STARTS THE RUN. Without this the dialog had no keyboard path at all: the inputs sit in a
   * plain div rather than a <form>, so pressing Enter after typing a niche did nothing whatsoever —
   * no run, no error, no feedback. The run itself was never broken; it simply was never asked for,
   * which is indistinguishable from a broken button to the person typing.
   *
   * Guarded by the same `canStart` the button uses, so Enter can never start a run the button would
   * have refused — and never a second one while the first is in flight.
   */
  const submitOnEnter = (e: React.KeyboardEvent<HTMLInputElement>) => {
    if (e.key !== "Enter") return;
    e.preventDefault();
    submit();
  };

  const reset = () => {
    setRunId(null);
    setAiSubmitted(false);
    start.reset();
    cancelRun.reset();
  };

  /**
   * CLEAR — put the form back exactly as it opens.
   *
   * LOCAL STATE ONLY. It clears what has been TYPED and returns the country to the default; it
   * starts nothing, cancels nothing and deletes nothing. No lead, no run and no prospect in the pool
   * is touched by it — a run already in flight keeps running on the server, and the board behind this
   * dialog is not even re-fetched.
   */
  const clearForm = () => {
    setCountry(DEFAULT_COUNTRY);
    setLocationsText("");
    setNichesText("");
    setLimitPerTask(20);
    start.reset();
  };

  const formDirty =
    locationsText !== "" ||
    nichesText !== "" ||
    limitPerTask !== 20 ||
    country !== DEFAULT_COUNTRY;

  const close = (next: boolean) => {
    // A run keeps executing on the server whether or not this dialog is open; closing only stops
    // watching it. Reset so the next open starts clean.
    if (!next) reset();
    onOpenChange(next);
  };

  return (
    <Dialog open={open} onOpenChange={close}>
      <DialogContent className="w-full sm:max-w-[560px]">
        <DialogHeader>
          <DialogTitle className="flex items-center gap-2">
            <Search className="h-4 w-4 text-teal-600" aria-hidden />
            Advanced Lead Discovery
          </DialogTitle>
          <DialogDescription>
            Search a factual business provider for prospects that are not in your pool yet. This is
            separate from the Nearby Clients board below, which keeps showing your existing pool.
          </DialogDescription>
        </DialogHeader>

        {run === undefined ? (
          <>
            <CountryChoice country={country} onCountry={setCountry} />
            {/* A chooser with one available option is not a choice. Without Google Places the only
                mode is the targeted search, so the selector is not drawn at all rather than shown
                with a dead half. */}
            {canRunNationwide && <ModeChoice mode={mode} onMode={setMode} />}
            {nationwide ? (
              <NationwideForm
                nichesText={nichesText}
                niches={niches}
                onNiches={setNichesText}
                onEnter={submitOnEnter}
              />
            ) : (
              <ScopeForm
                locationsText={locationsText}
                nichesText={nichesText}
                limitPerTask={limitPerTask}
                locations={locations}
                niches={niches}
                searchCount={searchCount}
                tooManyTasks={tooManyTasks}
                limitValid={limitValid}
                countryTyped={countryTyped}
                onEnter={submitOnEnter}
                onLocations={setLocationsText}
                onNiches={setNichesText}
                onLimit={setLimitPerTask}
              />
            )}
            {/* Why the AI action is missing, when the reason is one an operator can fix. Only on the
                Google flow, where AI Discovery would otherwise be offered; standard discovery above
                is unaffected either way. */}
            {canRunNationwide && (phase === "insufficient_credit" || aiStatus === "insufficient_credit") && (
              <p className="text-caption text-muted-foreground">
                AI Discovery is unavailable: the AI account has no credit. Standard discovery still
                works.
              </p>
            )}
            <PreRunAttribution
              effectiveProvider={effectiveProvider}
              canRunAi={canRunAi}
            />
          </>
        ) : (
          <RunPanel run={run} />
        )}

        <DialogFooter>
          {run?.isTerminal ? (
            <>
              <Button variant="outline" onClick={reset}>
                Start another
              </Button>
              <Button onClick={() => close(false)}>Done</Button>
            </>
          ) : (
            <>
              {runId === null && (
                <Button variant="ghost" onClick={clearForm} disabled={!formDirty || start.isPending}>
                  Clear
                </Button>
              )}
              <Button variant="outline" onClick={() => close(false)} disabled={start.isPending}>
                {runId === null ? "Cancel" : "Close"}
              </Button>
              {/* Stops the NEXT search; anything already found stays in the CRM. */}
              {runId !== null && run !== undefined && !run.isTerminal && (
                <Button
                  variant="destructive"
                  onClick={() => cancelRun.mutate(runId)}
                  disabled={cancelRun.isPending}
                >
                  {cancelRun.isPending && <Loader2 className="mr-2 h-4 w-4 animate-spin" aria-hidden />}
                  Cancel run
                </Button>
              )}
              {/* AI DISCOVERY — the same inputs, the same run, plus `ai_strategy: true`. Drawn only
                  when the backend reports AI available on the Google flow, so an action that cannot
                  work is never offered. */}
              {runId === null && canRunAi && (
                <Button variant="teal-outline" onClick={() => submit(true)} disabled={!canStart}>
                  {start.isPending && aiSubmitted ? (
                    <Loader2 className="mr-2 h-4 w-4 animate-spin" aria-hidden />
                  ) : (
                    <Sparkles className="mr-2 h-4 w-4" aria-hidden />
                  )}
                  Discover with AI
                </Button>
              )}
              <Button onClick={() => submit()} disabled={!canStart}>
                {start.isPending && !aiSubmitted && <Loader2 className="mr-2 h-4 w-4 animate-spin" aria-hidden />}
                {runId !== null
                  ? "Running…"
                  : nationwide
                    ? "Run nationwide discovery"
                    : `Run discovery${searchCount > 0 ? ` (${searchCount} search${searchCount === 1 ? "" : "es"})` : ""}`}
              </Button>
            </>
          )}
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}

/**
 * WHERE discovery may generate leads — the Philippines, and for now only the Philippines.
 *
 * A VISIBLE CONTROL RATHER THAN A HIDDEN ASSUMPTION. The restriction is real and enforced in three
 * independent places: the API refuses an unsupported country, the engine's geocoder is confined to it
 * so a foreign location cannot even resolve into a search, and the ingest checks every business
 * against the vendored national boundary. A user is better served by seeing that the field exists,
 * says Philippines, and cannot yet say anything else, than by discovering the limit when a search of
 * Kuala Lumpur silently returns nothing.
 *
 * Rendered as a real select, disabled with one option, because the shape of the control is the
 * honest signal: this is a setting with one supported value today, not a value that is unchangeable
 * in principle.
 */
function CountryChoice({
  country,
  onCountry,
}: {
  country: CountryCode;
  onCountry: (code: CountryCode) => void;
}) {
  const only = SUPPORTED_COUNTRIES.length === 1;

  return (
    <div className="flex flex-col gap-1.5">
      <Label htmlFor="discovery-country">Country</Label>
      <Select
        value={country}
        onValueChange={(v) => onCountry(v as CountryCode)}
        disabled={only}
      >
        <SelectTrigger id="discovery-country" className="w-full sm:w-64">
          <SelectValue placeholder="Philippines" />
        </SelectTrigger>
        <SelectContent>
          {SUPPORTED_COUNTRIES.map((c) => (
            <SelectItem key={c.code} value={c.code}>
              {c.label}
            </SelectItem>
          ))}
        </SelectContent>
      </Select>
      <p className="text-caption text-muted-foreground">
        Discovery currently supports <strong>{countryLabel(DEFAULT_COUNTRY)}</strong> only. Searches
        run inside the Philippines, and businesses found outside it are never added to your pool.
      </p>
    </div>
  );
}

/**
 * The two generation paths, named plainly.
 *
 * WHY A MODE AND NOT A CHECKBOX. These are not the same search with a wider box. "Specific
 * locations" asks a provider about places a person named and stops at what it returns; "Nationwide
 * Philippines" derives its own coverage from the national boundary, works area by area toward a
 * per-area target, and subdivides ground that comes back truncated. They have different units of
 * success, different budgets and different progress. Presenting one as a setting of the other would
 * make the 20-per-search number look like it applied to both — it does not, and the nationwide side
 * never sends it.
 */
function ModeChoice({
  mode,
  onMode,
}: {
  mode: DiscoveryRunScope;
  onMode: (mode: DiscoveryRunScope) => void;
}) {
  return (
    <div className="flex flex-col gap-1.5">
      <Label>What should be searched?</Label>
      <div className="grid grid-cols-1 gap-2 sm:grid-cols-2">
        <Button
          type="button"
          variant={mode === "scoped" ? "default" : "outline"}
          className="justify-start"
          aria-pressed={mode === "scoped"}
          onClick={() => onMode("scoped")}
        >
          <MapPin className="mr-2 h-4 w-4" aria-hidden />
          Specific locations
        </Button>
        <Button
          type="button"
          variant={mode === "nationwide" ? "default" : "outline"}
          className="justify-start"
          aria-pressed={mode === "nationwide"}
          onClick={() => onMode("nationwide")}
        >
          <Globe2 className="mr-2 h-4 w-4" aria-hidden />
          Nationwide Philippines
        </Button>
      </div>
    </div>
  );
}

/**
 * NATIONWIDE PHILIPPINES — one niche field and nothing else to decide.
 *
 * There is deliberately no location input, no area picker and no results-per-search box. Coverage
 * comes from the vendored national boundary, so there is nothing for a user to name; the per-area
 * target, the tile size and the safety budget are server configuration, because they are spending
 * and coverage policy rather than per-run preferences. What the form does instead is SAY what the run
 * will do, including the parts that are approximate — a number a user is told to expect must not turn
 * out to have been a promise.
 */
function NationwideForm({
  nichesText,
  niches,
  onNiches,
  onEnter,
}: {
  nichesText: string;
  niches: string[];
  onNiches: (v: string) => void;
  onEnter: (e: React.KeyboardEvent<HTMLInputElement>) => void;
}) {
  return (
    <div className="flex flex-col gap-4 py-2">
      <div className="rounded-md border border-border p-3">
        <p className="text-sm font-medium">Nationwide Philippines</p>
        <p className="mt-1 text-caption text-muted-foreground">
          Covers <strong>Luzon</strong>, the <strong>Visayas</strong> and <strong>Mindanao</strong> by
          dividing the Philippine map into search areas — not by working through a list of cities.
          Somewhere busy is split into smaller and smaller areas for as long as the provider keeps
          returning full results.
        </p>
        <p className="mt-2 text-caption text-muted-foreground">
          It keeps going until the provider has nothing new left to give or the run reaches its search
          budget — there is no fixed number of businesses per place. Anything already in your pool is
          counted as already known rather than added twice, and anything outside the Philippines is
          rejected.
        </p>
      </div>

      <div className="flex flex-col gap-1.5">
        <Label htmlFor="nationwide-niches">Business niches</Label>
        <Input
          id="nationwide-niches"
          onKeyDown={onEnter}
          value={nichesText}
          onChange={(e) => onNiches(e.target.value)}
          placeholder="bakery, dental clinic"
          autoComplete="off"
        />
        <p className="text-caption text-muted-foreground">
          A comma starts a <strong>different niche</strong>. Every niche is searched in every area.{" "}
          {niches.length} niche{niches.length === 1 ? "" : "s"}.
        </p>
      </div>

      {niches.length === 0 && (
        <p className="text-caption text-muted-foreground">
          Add at least one niche to start. This run may take a while and is stopped by its own budget
          — you can close this dialog and it keeps going.
        </p>
      )}
    </div>
  );
}

function ScopeForm({
  locationsText,
  nichesText,
  limitPerTask,
  locations,
  niches,
  searchCount,
  tooManyTasks,
  limitValid,
  countryTyped,
  onEnter,
  onLocations,
  onNiches,
  onLimit,
}: {
  locationsText: string;
  nichesText: string;
  limitPerTask: number;
  locations: string[];
  niches: string[];
  searchCount: number;
  tooManyTasks: boolean;
  limitValid: boolean;
  /** Locations that name the country itself — routed to the nationwide mode, never searched here. */
  countryTyped: string[];
  /** Enter starts the run, exactly as the button does. See `submitOnEnter`. */
  onEnter: (e: React.KeyboardEvent<HTMLInputElement>) => void;
  onLocations: (v: string) => void;
  onNiches: (v: string) => void;
  onLimit: (v: number) => void;
}) {
  return (
    <div className="flex flex-col gap-4 py-2">
      <div className="flex flex-col gap-1.5">
        <Label htmlFor="discovery-locations">Locations</Label>
        <Input
          id="discovery-locations"
          onKeyDown={onEnter}
          value={locationsText}
          onChange={(e) => onLocations(e.target.value)}
          /* NOT "Bacolod City, Philippines". The comma SEPARATES locations, so that example reads as
             two places — the city and the whole country — and typing the placeholder verbatim
             silently widened the search to national scope. The example must demonstrate the parsing
             rule it sits next to, so both halves are unmistakably separate places. */
          placeholder="Bacolod City, Iloilo City"
          autoComplete="off"
        />
        <p className="text-caption text-muted-foreground">
          A comma starts a <strong>different location</strong> — each one is searched separately.{" "}
          {/* The restriction, stated where the input is, not only in the Country field above. A
              location outside the Philippines cannot be resolved, so it never becomes a search. */}
          Locations must be in the <strong>Philippines</strong>; anywhere else is not searched.
        </p>
        {locations.length > 0 && (
          /* The parse, shown back. Whatever the rule says, the only reliable way to know how a
             comma was read is to see the list it produced before the run spends anything. */
          <p className="text-caption text-muted-foreground">
            Searching {locations.length} location{locations.length === 1 ? "" : "s"}:{" "}
            {locations.map((l, i) => (
              <span key={l}>
                {i > 0 && ", "}
                <span className="font-medium text-foreground">{l}</span>
              </span>
            ))}
          </p>
        )}
      </div>

      <div className="flex flex-col gap-1.5">
        <Label htmlFor="discovery-niches">Business niches</Label>
        <Input
          id="discovery-niches"
          onKeyDown={onEnter}
          value={nichesText}
          onChange={(e) => onNiches(e.target.value)}
          placeholder="bakery, dental clinic"
          autoComplete="off"
        />
        <p className="text-caption text-muted-foreground">
          A comma starts a <strong>different niche</strong>. {niches.length} niche
          {niches.length === 1 ? "" : "s"}.
        </p>
      </div>

      <div className="flex flex-col gap-1.5">
        <Label htmlFor="discovery-limit">Results per search</Label>
        <Input
          id="discovery-limit"
          type="number"
          min={1}
          max={MAX_LIMIT_PER_TASK}
          value={Number.isFinite(limitPerTask) ? limitPerTask : ""}
          onChange={(e) => onLimit(Number.parseInt(e.target.value, 10))}
          className="w-32"
        />
        <p className="text-caption text-muted-foreground">
          Up to {MAX_LIMIT_PER_TASK} — the provider&apos;s own ceiling for one search.
        </p>
      </div>

      {searchCount > 0 && !tooManyTasks && (
        <p className="text-caption text-muted-foreground">
          Every location is searched for every niche: <strong>{searchCount}</strong> search
          {searchCount === 1 ? "" : "es"} in total.
        </p>
      )}
      {tooManyTasks && (
        <AlertBanner
          message={`That is ${searchCount} searches. ${MAX_TASKS_PER_RUN} is the most one run may schedule — narrow the locations or niches, or start more than one run.`}
        />
      )}
      {!limitValid && (
        <AlertBanner message={`Results per search must be between 1 and ${MAX_LIMIT_PER_TASK}.`} />
      )}
      {countryTyped.length > 0 && (
        /* Not a rejected place name — a redirected one. Searching the country as a single location
           asks the provider for one box around the whole archipelago, which answers with a few dozen
           businesses and looks like national coverage. The mode above does it properly. */
        <AlertBanner
          message={`"${countryTyped[0]}" is the whole country — switch to "Nationwide Philippines" above to search it properly. Searching it as one location returns only a handful of results.`}
        />
      )}
    </div>
  );
}

/**
 * Discovery progress, in its OWN visual language.
 *
 * This used to render the shared priority-toned `ProgressBar` with `tone="high"` purely to get a
 * filled bar — but `high` is a Lead Priority token that resolves to red, so a finished, fully
 * successful run was drawn in the same colour the board uses for alarm. Progress through a search is
 * not a priority, so it no longer borrows that scale: the neutral track fills in the product's
 * primary colour, and red is kept for the one state that has genuinely failed. The shared component
 * and its priority semantics are untouched and still used by the dashboard.
 */
function RunProgress({ percent, failed }: { percent: number; failed: boolean }) {
  const clamped = Math.max(0, Math.min(100, percent));

  return (
    <Progress value={clamped}>
      <ProgressTrack className="h-2">
        <ProgressIndicator
          className={cn("rounded-full", failed && "bg-destructive")}
          style={{ width: `${clamped}%` }}
        />
      </ProgressTrack>
    </Progress>
  );
}

/**
 * WHICH ENGINE, AND WHOSE DATA — said before a run is started rather than only after it finishes.
 *
 * THE CONFUSION THIS ANSWERS. "Discover with AI" is offered only on the Google flow, so a run it
 * starts is planned by AI and sourced from Google at the same time. With nothing naming the two
 * roles, the results read as purely Google-discovered and the AI's part went unrecorded.
 *
 * IT CLAIMS NOTHING ABOUT A RUN THAT HAS NOT HAPPENED. The AI row is drawn only when the backend
 * reports AI actually available (`canRunAi`), and it says the engine would be used when the AI
 * action is chosen — never that it will be, because the ordinary Run discovery button is still
 * right beside it. What AI did is reported afterwards, from the run's own recorded outcome.
 *
 * Presentation only: both values are read from the capability the dialog already fetched. It does
 * not choose, change or resolve a provider, and it never touches a discovered lead.
 */
function PreRunAttribution({
  effectiveProvider,
  canRunAi,
}: {
  effectiveProvider: string | null;
  canRunAi: boolean;
}) {
  if (effectiveProvider === null) return null;

  return (
    <div className="rounded-md border border-border p-3">
      <div className="flex items-center justify-between gap-3">
        <span className="text-caption text-muted-foreground">Business data source</span>
        <span className="text-sm font-medium">{providerLabel(effectiveProvider)}</span>
      </div>
      {canRunAi && (
        <>
          <div className="mt-2 flex items-center justify-between gap-3">
            <span className="flex items-center gap-1.5 text-caption text-muted-foreground">
              <Sparkles className="h-3.5 w-3.5" aria-hidden />
              AI engine
            </span>
            <span className="text-sm font-medium">{AI_ENGINE_NAME}</span>
          </div>
          <p className="mt-1 text-caption text-muted-foreground">
            Used when you choose Discover with AI. {AI_ENGINE_NAME} plans how the searches are
            phrased; every business still comes from {providerLabel(effectiveProvider)}.
          </p>
        </>
      )}
    </div>
  );
}

/** Live progress, then the outcome. Every number here comes from the API; none is computed locally. */
function RunPanel({ run }: { run: DiscoveryRun }) {
  const { progress, counters, provider } = run;
  const failed = run.status === "failed";
  const nationwide = run.scopeClass === "nationwide";
  // Planning tiles the national polygon before any area exists. Saying so beats showing "0 of 0".
  const planning = nationwide && run.phase === "planning";
  // A run that asked for AI and was stopped because the AI account could not pay.
  const aiCreditStop = discoveryPhase({ submitting: false, run }) === "insufficient_credit";
  const aiLabel = aiOutcomeLabel(run);
  const aiMessage = aiOutcomeMessage(run);

  return (
    <div className="flex flex-col gap-4 py-2">
      <div className="flex flex-col gap-2">
        <div className="flex items-center justify-between gap-3">
          <span className="flex items-center gap-2 text-sm font-medium">
            {nationwide && <Globe2 className="h-4 w-4 text-teal-600" aria-hidden />}
            {run.status === "running" &&
              (planning
                ? "Mapping the country…"
                : nationwide
                  ? `Searching… ${progress.cellsFirstPassDone} of ${progress.cellsPlanned} map areas`
                  : "Searching…")}
            {run.status === "pending" && "Queued…"}
            {run.status === "completed" &&
              (run.stopReason === "budget_limited" ? "Stopped at its budget" : "Discovery complete")}
            {run.status === "failed" && (aiCreditStop ? "AI Discovery stopped — no AI credit" : "Discovery failed")}
            {run.status === "cancelled" && "Discovery cancelled"}
          </span>
          <span className="text-caption text-muted-foreground">
            {/* NATIONWIDE COUNTS MAP AREAS, NOT SEARCHES. Its search total climbs as it subdivides
                dense ground, so a "x of y searches" label would keep moving its own goalposts. */}
            {nationwide
              ? `${progress.cellsFirstPassDone} of ${progress.cellsPlanned} map areas`
              : `${progress.targetsCompleted + progress.targetsFailed} of ${progress.targetsTotal} searches`}
          </span>
        </div>
        <RunProgress percent={progress.percent} failed={failed} />
        {nationwide && !planning && (
          <div className="flex flex-wrap items-center justify-between gap-2 text-caption text-muted-foreground">
            <span>
              {progress.cellsSearched} searched
              {progress.cellsSubdivided > 0
                ? ` · ${progress.cellsSubdivided} split for a closer look`
                : ""}
              {" · "}
              {progress.targetsTotal} search{progress.targetsTotal === 1 ? "" : "es"} run
            </span>
            {/* The budget, stated while it is being spent — so a run that stops early has already
                explained why, instead of leaving its result to look like a fact about the country. */}
            <span>
              Budget {progress.budgetUsed}/{progress.budgetTotal}
            </span>
          </div>
        )}
      </div>

      {/* TWO ROLES, TWO ROWS. "Provider used" had to carry both questions at once — which engine
          planned the search and where the businesses came from — so an AI-planned Google run read as
          purely Google-discovered. Naming the roles separates them. The values are the same two
          fields as before (`provider.effective` and the run's recorded AI outcome); nothing about
          provider resolution, ingestion or a lead's `source` is touched. */}
      <div className="rounded-md border border-border p-3">
        <div className="flex items-center justify-between gap-3">
          <span className="text-caption text-muted-foreground">Business data source</span>
          <span className="text-sm font-medium">{providerLabel(provider.effective)}</span>
        </div>
        {provider.isFallback && (
          <p className="mt-2 text-caption text-muted-foreground">
            {providerLabel(provider.requested)} was configured but did not run
            {provider.degradedReason !== null ? ` (${provider.degradedReason})` : ""}. These results
            came from {providerLabel(provider.effective)}.
          </p>
        )}
        {/* AI planning, stated from the run's recorded outcome — never from which button started it.
            Absent entirely for an ordinary run that AI did not shape. */}
        {aiLabel !== null && (
          <>
            <div className="mt-2 flex items-center justify-between gap-3">
              <span className="flex items-center gap-1.5 text-caption text-muted-foreground">
                <Sparkles className="h-3.5 w-3.5" aria-hidden />
                AI engine
              </span>
              <span className="text-sm font-medium">{aiLabel}</span>
            </div>
            {aiMessage !== null && (
              <p className="mt-1 text-caption text-muted-foreground">{aiMessage}</p>
            )}
          </>
        )}
      </div>

      {/* THESE NUMBERS BELONG TO THIS RUN AND TO NOTHING ELSE — said in words, because the board
          behind this dialog shows a pool count that a reader could otherwise take for a national
          total. "Found" is what the provider returned for the searches this run actually made; it is
          not an estimate of how many businesses exist in the Philippines, and nothing here should be
          read as one. */}
      <p className="text-caption text-muted-foreground">
        {nationwide ? "This nationwide run so far" : "This run"} — not your pool total.
      </p>
      <dl className="grid grid-cols-2 gap-3 sm:grid-cols-4">
        <Stat label="Found" value={counters.discovered} />
        <Stat label="New leads" value={counters.inserted} />
        <Stat label="Already known" value={counters.duplicates} />
        <Stat label="Outside PH / unusable" value={counters.rejected} />
      </dl>

      {/* Coverage, stated as the fraction of the country it actually is. A run that searched 14 of
          736 map areas has covered 2% of the plan, and saying so is the difference between a result
          and a misleading one. */}
      {nationwide && !planning && progress.cellsPlanned > 0 && (
        <dl className="grid grid-cols-2 gap-3 sm:grid-cols-4">
          <Stat label="Map areas planned" value={progress.cellsPlanned} />
          <Stat label="Areas searched" value={progress.cellsSearched} />
          <Stat label="Exhausted" value={progress.cellsExhausted} />
          <Stat label="Still had more" value={progress.cellsCapped} />
        </dl>
      )}

      {progress.targetsFailed > 0 && !failed && (
        <AlertBanner
          message={`${progress.targetsFailed} of ${progress.targetsTotal} searches did not complete. The rest succeeded and their results were saved.`}
        />
      )}
      {failed && <AlertBanner message={run.errorMessage ?? "The discovery run did not complete."} />}

      {/* WHY THE RUN STOPPED, stated rather than left to be inferred from the numbers.
          `exhausted` and `budget_limited` produce similar-looking totals and mean opposite things:
          one says the provider had no more to give, the other says we stopped asking. Only the first
          says anything at all about the Philippines. */}
      {nationwide && !run.isTerminal && (
        <p className="text-caption text-muted-foreground">
          Still searching — {progress.cellsPlanned - progress.cellsFirstPassDone} of{" "}
          {progress.cellsPlanned} map areas left in this pass, {" "}
          {progress.budgetTotal - progress.budgetUsed} searches of budget remaining. Totals below
          will keep rising while it runs.
        </p>
      )}
      {nationwide && run.stopReason === "budget_limited" && (
        <AlertBanner
          message={`This run used its full budget of ${progress.budgetTotal} searches and stopped with ${progress.cellsPlanned - progress.cellsFirstPassDone} of ${progress.cellsPlanned} map areas still unsearched. Nothing is implied about those places — run again with a larger budget to continue.`}
        />
      )}
      {nationwide && run.stopReason === "exhausted" && (
        <p className="text-caption text-muted-foreground">
          Every one of the {progress.cellsPlanned} map areas was searched until the provider had
          nothing new left to give for these niches, at this level of detail.
        </p>
      )}
      {nationwide && run.isTerminal && progress.cellsCapped > 0 && (
        <p className="text-caption text-muted-foreground">
          {progress.cellsCapped} map area{progress.cellsCapped === 1 ? "" : "s"} still had more to
          find when the search reached how far it may divide. Those areas are not finished.
        </p>
      )}

      {run.isTerminal && counters.inserted > 0 && (
        <p className="text-caption text-muted-foreground">
          {counters.inserted} new business{counters.inserted === 1 ? "" : "es"} added to your
          prospect pool — the board behind this dialog has been refreshed.
        </p>
      )}

      {/* PER-AREA SUMMARY — a label for reading the run, never a unit of work. Grouped after the
          fact from the searches; the run would cover identical ground with this list deleted. */}
      {nationwide && run.areas !== undefined && run.areas.length > 0 && (
        <div className="max-h-48 overflow-y-auto rounded-md border border-border">
          <ul className="divide-y divide-border">
            {/* KEYED BY WHAT ACTUALLY GROUPED THE ROW. The server builds this list with
                `groupBy('area', 'island_group')`, so the row's identity is BOTH columns — keying on
                the label alone collided the moment one label spanned two groups, which is a real
                shape in this data: a cell takes its label from the nearest anchor city and its group
                from the major-landmass boxes, and those are independent derivations. Measured on the
                live runs, "General Santos" and "Zamboanga" each appear under both `mindanao` and
                `visayas`. Both rows are true and both are shown, each with its own badge; only the
                key was wrong. Matching the grouping key fixes every such label, not these two. */}
            {run.areas.map((a) => (
              <li
                key={`${a.area}|${a.islandGroup}`}
                className="flex items-center justify-between gap-3 px-3 py-2"
              >
                <span className="flex min-w-0 items-center gap-2">
                  <span className="truncate text-caption font-medium">{a.area}</span>
                  <Badge variant="outline" className="shrink-0">
                    {islandGroupLabel(a.islandGroup)}
                  </Badge>
                </span>
                <span className="shrink-0 text-caption text-muted-foreground">
                  {a.inserted} added · {a.cells} area{a.cells === 1 ? "" : "s"}
                </span>
              </li>
            ))}
          </ul>
        </div>
      )}

      {!nationwide && run.targets !== undefined && run.targets.length > 0 && (
        <div className="max-h-40 overflow-y-auto rounded-md border border-border">
          <ul className="divide-y divide-border">
            {run.targets.map((t) => (
              <li key={`${t.area}|${t.category}`} className="flex items-center justify-between gap-3 px-3 py-2">
                <span className="truncate text-caption">
                  {t.category} · {t.area}
                </span>
                <span className="shrink-0 text-caption text-muted-foreground">
                  {t.status === "completed" ? `${t.inserted} added` : t.status}
                </span>
              </li>
            ))}
          </ul>
        </div>
      )}
    </div>
  );
}

function Stat({ label, value }: { label: string; value: number }) {
  return (
    <div className="rounded-md border border-border p-3">
      <dt className="text-caption text-muted-foreground">{label}</dt>
      <dd className="text-lg font-semibold">{value}</dd>
    </div>
  );
}
