"use client";

import { useState } from "react";
import { useForm } from "react-hook-form";
import { zodResolver } from "@hookform/resolvers/zod";
import { z } from "zod";
import { AlertTriangle, CheckCircle2, Lock, Radar, Sparkles, Wallet } from "lucide-react";
import { Card } from "@/modules/crm/components/ui/card";
import { Button } from "@/modules/crm/components/ui/button";
import { Badge } from "@/modules/crm/components/ui/badge";
import { Switch } from "@/modules/crm/components/ui/switch";
import { Skeleton } from "@/modules/crm/components/ui/skeleton";
import { ConfirmDialog } from "@/modules/crm/components/shared/confirm-dialog/confirm-dialog";
import { ErrorState } from "@/modules/crm/components/shared/error-state/error-state";
import { FIELD } from "@/modules/crm/features/settings/components/form-styles";
import { cn } from "@/modules/crm/lib/utils";
import {
  useClearNerbyCredential,
  useConfigureNerbyProvider,
  useNerbyIntegration,
  useSelectNerbyProvider,
  useTestNerbyProvider,
  testStatusLabel,
  type NerbyIntegration,
  type NerbyProviderConfig,
} from "@/modules/crm/features/settings/hooks/use-nerby-integration";

const cardClass = "border border-border bg-card p-6 shadow-card";

/**
 * CONFIGURED IS NOT ACTIVE. The distinction this whole card exists to make legible.
 *
 * An admin may store an OpenAI credential and verify it without switching production discovery over,
 * and must be able to see at a glance which of those they have done. Four states, in the order they
 * are reached — only the last one means this provider is actually generating leads:
 *
 *   Not configured -> Configured -> Verified -> Active
 *
 * `Active` is computed from the EFFECTIVE provider, never from the radio selection. A provider that
 * is selected but cannot run (no key, switched off) is Configured or Verified, never Active — which
 * is precisely the state a green tick beside "OpenAI" would misrepresent.
 */
type ProviderState = {
  label: string;
  tone: "active" | "ready" | "pending" | "blocked" | "idle";
  hint: string;
};

function providerState(
  provider: NerbyProviderConfig,
  integration: NerbyIntegration,
): ProviderState {
  const isRunning = provider.provider === integration.effectiveProvider;

  // CHECKED BEFORE "Active", deliberately. A credential we KNOW cannot pay must never be painted as
  // healthy, and that holds whether or not it is the provider currently selected — this is the same
  // rule stated at the top of this file ("a provider that is selected but cannot run is Configured
  // or Verified, never Active"), applied to the one failure that is certain to persist until a
  // person acts on it. Every other status keeps its existing precedence.
  if (provider.lastTestStatus === "insufficient_credit") {
    return {
      label: "No credit",
      tone: "blocked",
      hint: "The key works, but the account has no credit left. Add credit at the provider, then run Test connection again.",
    };
  }

  if (isRunning) {
    return {
      label: "Active",
      tone: "active",
      hint: "Currently generating the businesses in your prospect pool.",
    };
  }

  if (!provider.requiresCredentials) {
    // The baseline is always available; it simply is not the one running right now.
    return {
      label: "Default",
      tone: "idle",
      hint: "Always available. Selected automatically whenever no other provider can run.",
    };
  }

  if (!provider.hasApiKey) {
    return {
      label: "Not configured",
      tone: "idle",
      hint: "Add an API key below to configure this provider. Nothing changes until you do.",
    };
  }

  if (provider.lastTestStatus === "ok") {
    return {
      label: "Verified",
      tone: "ready",
      hint: "Credential stored and confirmed working. Select this provider to make it active.",
    };
  }

  return {
    label: "Configured",
    tone: "pending",
    hint: "Credential stored but not yet confirmed. Run Test connection to verify it.",
  };
}

const STATE_CLASS: Record<ProviderState["tone"], string> = {
  // teal-600 (#009588) under white is 3.7:1 — below the 4.5:1 AA floor for this 12px label.
  // teal-700 takes it to 5.2:1 while staying the same solid teal chip. Dark only; light is unchanged.
  active: "bg-teal-600 text-white dark:bg-teal-700",
  // `ready`/`pending` were written as OUTLINE chips — border + text, no surface. The call sites use
  // Badge's default variant, whose `bg-primary` (bright cyan) therefore survives tailwind-merge and
  // ends up under this text. Amber-on-cyan measured 1.17:1. Each state now carries its own surface,
  // using the same amber/teal chip pattern as website-intel-badge.tsx.
  ready: "border-teal-600 bg-teal-100 text-teal-800 dark:border-teal-700 dark:bg-teal-950/50 dark:text-teal-300",
  pending: "border-amber-300 bg-amber-100 text-amber-800 dark:border-amber-800 dark:bg-amber-950/50 dark:text-amber-300",
  // Same chip pattern as `ready`/`pending`, in the destructive hue: this is a blocker an operator
  // has to act on, not a step that has yet to happen. rose-800 on rose-100 measures 7.4:1.
  blocked: "border-rose-300 bg-rose-100 text-rose-800 dark:border-rose-800 dark:bg-rose-950/50 dark:text-rose-300",
  idle: "border-border text-muted-foreground",
};

/**
 * Settings → Integrations → Nearby Lead Generation. The control centre for which engine fills the
 * discovered-business pool.
 *
 * Reached only by Admin / Super Admin: `/zerem/settings` is `minRole: crm_admin` in PAGE_ACCESS and
 * the RoleGuard matches by prefix, so this route inherits the gate — and all five endpoints behind it
 * are `role:super_admin|crm_admin` regardless of what the client believes. Hiding controls here would
 * be presentation; the server is the protection.
 *
 * THE ONE THING THIS UI MUST NEVER DO is let a CONFIGURED provider read as an ACTIVE one. An admin
 * may store and verify an OpenAI credential without switching production discovery over, so the API
 * reports the selected and the effective provider separately and this card shows both side by side,
 * always — plus a per-provider state chip that only ever reads "Active" for the engine actually
 * running, and a warning banner when the two diverge.
 *
 * It also never holds a credential. The API sends `hasApiKey` and the last four characters and
 * nothing else, so the key input is write-only: it starts empty, shows a masked placeholder, and is
 * never populated from a response.
 */
export function NerbyIntegrationCard() {
  const { integration, isLoading, isError, isForbidden, retry } = useNerbyIntegration();

  return (
    <Card className={cardClass}>
      <div className="flex flex-col gap-5">
        <header className="flex items-start justify-between gap-4">
          <div className="flex items-start gap-3">
            <span className="mt-0.5 flex size-9 items-center justify-center rounded-lg bg-muted">
              <Radar className="size-4 text-teal-700 dark:text-teal-400" aria-hidden />
            </span>
            <div>
              <h3 className="text-base font-semibold text-foreground">Nearby Lead Generation</h3>
              <p className="mt-0.5 text-sm text-muted-foreground">
                Which engine discovers the businesses that fill your prospect pool.
              </p>
            </div>
          </div>
        </header>

        {isLoading ? (
          <div className="flex flex-col gap-3" aria-busy>
            <Skeleton className="h-16 w-full" />
            <Skeleton className="h-16 w-full" />
            <Skeleton className="h-16 w-full" />
          </div>
        ) : isForbidden ? (
          <p className="rounded-lg border border-border bg-muted/40 p-4 text-sm text-muted-foreground">
            The discovery engine is set by the platform administrator, not per organisation —
            the provider account is bought and paid for centrally. Everything else on this page
            is yours to change.
          </p>
        ) : isError || !integration ? (
          <ErrorState
            message="Could not load the Nearby provider configuration."
            onRetry={() => void retry()}
          />
        ) : (
          <Body integration={integration} />
        )}
      </div>
    </Card>
  );
}

/**
 * The one provider that is an INTELLIGENCE layer rather than a source of businesses.
 *
 * Everything else in the list supplies facts — records, identifiers, coordinates — and belongs in
 * the factual group automatically, including any provider added later. Only this one is special,
 * so only this one is named.
 */
const INTELLIGENCE_PROVIDER = "openai";

function SectionHeading({ title, detail }: { title: string; detail: string }) {
  return (
    <div className="mb-2">
      <h4 className="text-xs font-semibold uppercase tracking-[0.08em] text-muted-foreground">
        {title}
      </h4>
      <p className="mt-1 text-xs text-muted-foreground">{detail}</p>
    </div>
  );
}

/**
 * TWO SECTIONS, BECAUSE THERE ARE TWO DECISIONS.
 *
 * Presenting all three options as one radio list made them read as rivals, so choosing AI looked
 * like the way to "turn AI on" — when it actually selects the AI-assisted OpenStreetMap lane, which
 * is why enabling AI appeared to give an OSM map instead of Google's. Splitting the list says what
 * the backend has always done: one choice decides where businesses come FROM (and therefore which
 * map draws them), and a separate switch decides whether AI helps plan the search.
 *
 * The radio itself is unchanged — selecting AI still selects the AI-assisted OSM lane, because that
 * is currently the only way to reach it. Nothing here alters provider resolution.
 */
function Body({ integration }: { integration: NerbyIntegration }) {
  const factual = integration.providers.filter((p) => p.provider !== INTELLIGENCE_PROVIDER);
  const intelligence = integration.providers.filter((p) => p.provider === INTELLIGENCE_PROVIDER);

  return (
    <>
      <EffectiveProviderSummary integration={integration} />

      <section>
        <SectionHeading
          title="Factual discovery provider"
          detail="Where discovered businesses actually come from. This choice also decides which map the Nearby board draws — OpenStreetMap results on the OpenStreetMap map, Google Places results on Google's."
        />
        <div className="flex flex-col gap-3">
          {factual.map((provider) => (
            <ProviderOption key={provider.provider} provider={provider} integration={integration} />
          ))}
        </div>
      </section>

      {intelligence.length > 0 ? (
        <section>
          <SectionHeading
            title="AI intelligence"
            detail="Optional. AI plans HOW to search — query wording, place types, sub-areas — and never supplies a business, identifier or coordinate. Turning it ON assists whichever factual provider is selected, including Google Places. Selecting it below instead makes AI-assisted OpenStreetMap the factual provider."
          />
          <div className="flex flex-col gap-3">
            {intelligence.map((provider) => (
              <ProviderOption
                key={provider.provider}
                provider={provider}
                integration={integration}
                isIntelligence
              />
            ))}
          </div>
        </section>
      ) : null}

      <CapabilityTiers />
    </>
  );
}

/**
 * Configured vs effective, stated in one place and never merged.
 *
 * The chip is always present, not only on failure: an admin should be able to answer "what is
 * running right now?" without interpreting anything. The banner appears only when the two diverge.
 */
function EffectiveProviderSummary({ integration }: { integration: NerbyIntegration }) {
  const { isFallback, effectiveProviderLabel, reason } = integration;

  const selected = integration.providers.find((p) => p.provider === integration.activeProvider);

  return (
    <div className="flex flex-col gap-3">
      {/* TWO FACTS, SIDE BY SIDE. Stated separately rather than merged into one line, because
          "which provider did we choose" and "which provider is producing leads right now" can
          legitimately differ — and a reader who has to infer the second from the first will
          eventually infer it wrong. */}
      <div className="grid gap-2 sm:grid-cols-2">
        <div className="flex flex-wrap items-center gap-2 rounded-lg border border-border-subtle bg-muted/40 px-3 py-2">
          <span className="text-xs font-semibold uppercase tracking-[0.06em] text-muted-foreground">
            Selected
          </span>
          <Badge variant="outline">{selected?.label ?? integration.activeProvider}</Badge>
        </div>
        <div
          className={cn(
            "flex flex-wrap items-center gap-2 rounded-lg border px-3 py-2",
            isFallback
              ? "border-amber-300 bg-amber-50/50 dark:border-amber-700/60 dark:bg-muted/40"
              : "border-teal-600/40 bg-teal-50/40 dark:border-teal-700/60 dark:bg-muted/40",
          )}
        >
          <span className="text-xs font-semibold uppercase tracking-[0.06em] text-muted-foreground">
            Active now
          </span>
          <Badge className={cn("gap-1", isFallback ? STATE_CLASS.pending : STATE_CLASS.active)}>
            {isFallback ? (
              <AlertTriangle className="size-3" aria-hidden />
            ) : (
              <CheckCircle2 className="size-3" aria-hidden />
            )}
            {effectiveProviderLabel}
          </Badge>
          {reason === "default_no_configuration" ? (
            <span className="text-xs text-muted-foreground">(nothing configured yet)</span>
          ) : null}
        </div>
      </div>

      {isFallback ? (
        <div
          role="status"
          className="flex items-start gap-2 rounded-lg border border-amber-300 bg-amber-50 px-3 py-2.5 text-sm text-amber-900 dark:border-amber-700/60 dark:bg-amber-950/20 dark:text-amber-200"
        >
          <AlertTriangle className="mt-0.5 size-4 shrink-0" aria-hidden />
          <p>{fallbackMessage(integration)}</p>
        </div>
      ) : null}
    </div>
  );
}

/** Plain language for each divergence, naming what runs rather than what was chosen. */
function fallbackMessage(integration: NerbyIntegration): string {
  const selected = integration.providers.find((p) => p.provider === integration.activeProvider);
  const selectedLabel = selected?.label ?? integration.activeProvider;
  const running = integration.effectiveProviderLabel;

  switch (integration.reason) {
    case "provider_not_configured":
      return `${selectedLabel} is selected but has no API key, so ${running} is still handling discovery. Add a key below to switch over.`;
    case "provider_disabled":
      return `${selectedLabel} is selected but switched off, so ${running} is still handling discovery.`;
    case "unknown_provider":
      return `The saved provider is not recognised by this version of the CRM, so ${running} is handling discovery.`;
    default:
      return `${running} is handling discovery.`;
  }
}

function ProviderOption({
  provider,
  integration,
  isIntelligence = false,
}: {
  provider: NerbyProviderConfig;
  integration: NerbyIntegration;
  /** Renders this row as the optional intelligence layer rather than a source of businesses. */
  isIntelligence?: boolean;
}) {
  const select = useSelectNerbyProvider();
  const isSelected = provider.provider === integration.activeProvider;
  const isRunning = provider.provider === integration.effectiveProvider;
  const isDefault = provider.provider === integration.defaultProvider;
  const state = providerState(provider, integration);
  // Selected but NOT running — the state that must never look like success.
  const selectedButIdle = isSelected && !isRunning;

  return (
    <div
      className={cn(
        "rounded-lg border p-4 transition-colors",
        selectedButIdle
          ? "border-amber-300 bg-amber-50/40 dark:border-amber-700/60 dark:bg-card"
          : isSelected
            ? "border-teal-600 bg-teal-50/40 dark:border-teal-700/60 dark:bg-card"
            : "border-border bg-card",
      )}
    >
      <div className="flex items-start justify-between gap-4">
        <label className="flex cursor-pointer items-start gap-3">
          <input
            type="radio"
            name="nerby-provider"
            className="mt-1 size-4 accent-teal-700 dark:accent-teal-400"
            checked={isSelected}
            disabled={select.isPending}
            onChange={() => select.mutate(provider.provider)}
          />
          <span>
            <span className="flex flex-wrap items-center gap-2">
              {/* Named for what SELECTING it does. The server's label ("OpenAI (AI-Powered)") reads
                  as a peer provider, which is the confusion this card exists to remove; the factual
                  lane it actually selects is OpenStreetMap. Presentation only — the server's
                  vocabulary is untouched. */}
              <span className="text-sm font-semibold text-foreground">
                {isIntelligence ? "AI-assisted OpenStreetMap" : provider.label}
              </span>
              {/* ONE state chip, not a pile of overlapping badges. Which lifecycle stage this
                  provider is at, ending at Active only when it is genuinely running. */}
              <Badge
                variant={state.tone === "active" ? "default" : "outline"}
                className={STATE_CLASS[state.tone]}
              >
                {state.label}
              </Badge>
              {isDefault && state.label !== "Default" ? (
                <Badge variant="outline" className="text-muted-foreground">
                  Baseline
                </Badge>
              ) : null}
              {/* Only the intelligence layer is AI-powered. This was keyed to `requiresCredentials`,
                  which is also true of Google Places — so a purely factual provider wore an AI badge,
                  the exact conflation this grouping removes. */}
              {isIntelligence ? (
                <Badge variant="outline" className="gap-1">
                  <Sparkles className="size-3" aria-hidden />
                  AI-Powered
                </Badge>
              ) : null}
              {isSelected && !isRunning ? (
                <Badge variant="outline" className="border-amber-300 text-amber-800 dark:border-amber-800 dark:text-amber-300">
                  Selected, not running
                </Badge>
              ) : null}
            </span>
            <span className="mt-1 block text-xs text-muted-foreground">
              {provider.requiresCredentials
                ? "Proposes candidate businesses, then resolves each one against OpenStreetMap before it can become a prospect."
                : "Queries OpenStreetMap directly by category. No API key, no vendor, no billing."}
            </span>
            <span className="mt-1 block text-xs text-muted-foreground">{state.hint}</span>
          </span>
        </label>
      </div>

      {provider.requiresCredentials ? (
        <CredentialPanel provider={provider} />
      ) : null}
    </div>
  );
}

const keySchema = z.object({
  // Shape only, matching the server: length and no whitespace. Deliberately not a `sk-` prefix
  // check — the prefix is a vendor convention that has already changed once.
  apiKey: z
    .string()
    .trim()
    .min(20, "That does not look like an API key. Paste the full key.")
    .regex(/^\S+$/, "An API key cannot contain spaces — check for a stray copy/paste."),
});

const modelSchema = z.object({
  model: z.string().trim().max(120, "Model name is too long."),
});

/**
 * The spend limit is optional TEXT, not a number input, because three inputs are meaningful and a
 * numeric field cannot express all of them: an amount, blank (no limit), and 0 (stop everything).
 * A `<input type="number">` conflates blank and zero in several browsers, which here is the
 * difference between "unlimited" and "refuse every request".
 *
 * The server re-validates and owns the ceiling; this only stops an obviously wrong value making a
 * round trip.
 */
const spendSchema = z.object({
  spendLimitUsd: z
    .string()
    .trim()
    .refine(
      (v) => v === "" || (/^\d+(\.\d{1,2})?$/.test(v) && Number.isFinite(Number(v))),
      "Enter an amount in USD such as 10 or 49.50, or leave blank for no limit.",
    ),
});

/**
 * The credential + options panel for a provider that needs a key.
 *
 * The key field is WRITE-ONLY: it starts empty and is never populated from a response, because the
 * API never sends a key. When one is stored the placeholder shows the last four characters — enough
 * for an admin to recognise which key is in place, and nothing more.
 */
function CredentialPanel({ provider }: { provider: NerbyProviderConfig }) {
  const configure = useConfigureNerbyProvider();
  const clear = useClearNerbyCredential();
  const test = useTestNerbyProvider();
  const [confirmRemove, setConfirmRemove] = useState(false);

  const keyForm = useForm<z.infer<typeof keySchema>>({
    resolver: zodResolver(keySchema),
    defaultValues: { apiKey: "" },
  });

  const modelForm = useForm<z.infer<typeof modelSchema>>({
    resolver: zodResolver(modelSchema),
    defaultValues: { model: (provider.options?.model as string | undefined) ?? "" },
  });

  const busy = configure.isPending || clear.isPending || test.isPending;

  return (
    <div className="mt-4 flex flex-col gap-4 border-t border-border-subtle pt-4">
      <p className="rounded-lg bg-muted/40 px-3 py-2 text-xs text-muted-foreground">
        You can store and verify a credential here without changing which provider is active.
        Discovery only switches over when you select this provider above.
      </p>

      <div className="flex flex-wrap items-center justify-between gap-3">
        <div>
          <p className="text-sm font-medium text-foreground">Enable this provider</p>
          <p className="text-xs text-muted-foreground">
            Selecting a provider does not switch it on — enabling is a separate step.
          </p>
        </div>
        <Switch
          checked={provider.enabled}
          disabled={busy}
          aria-label={`Enable ${provider.label}`}
          onCheckedChange={(checked) =>
            configure.mutate({ provider: provider.provider, enabled: checked })
          }
        />
      </div>

      {/* --- API key: write-only --- */}
      <form
        className="flex flex-col gap-2"
        onSubmit={keyForm.handleSubmit(({ apiKey }) =>
          configure.mutate(
            { provider: provider.provider, api_key: apiKey },
            { onSuccess: () => keyForm.reset({ apiKey: "" }) },
          ),
        )}
      >
        <label htmlFor={`${provider.provider}-key`} className="flex items-center gap-1.5 text-sm font-medium text-foreground">
          <Lock className="size-3.5 text-muted-foreground" aria-hidden />
          API key
        </label>
        <div className="flex flex-wrap gap-2">
          <input
            id={`${provider.provider}-key`}
            type="password"
            autoComplete="off"
            className={cn(FIELD, "max-w-sm flex-1")}
            placeholder={
              provider.hasApiKey ? `••••••••${provider.apiKeyLast4 ?? ""}` : "Paste the API key"
            }
            {...keyForm.register("apiKey")}
          />
          <Button type="submit" size="sm" disabled={busy}>
            {provider.hasApiKey ? "Replace key" : "Save key"}
          </Button>
          {provider.hasApiKey ? (
            <Button
              type="button"
              size="sm"
              variant="outline"
              disabled={busy}
              onClick={() => setConfirmRemove(true)}
            >
              Remove
            </Button>
          ) : null}
        </div>
        <p className="text-xs text-muted-foreground">
          Stored encrypted on the server and never sent back to the browser. Only the last four
          characters are ever shown.
        </p>
        {keyForm.formState.errors.apiKey ? (
          <p className="text-xs text-destructive">{keyForm.formState.errors.apiKey.message}</p>
        ) : null}
      </form>

      {/* --- Model --- */}
      {provider.optionKeys.includes("model") ? (
        <form
          className="flex flex-col gap-2"
          onSubmit={modelForm.handleSubmit(({ model }) =>
            configure.mutate({ provider: provider.provider, options: { model: model.trim() } }),
          )}
        >
          <label htmlFor={`${provider.provider}-model`} className="text-sm font-medium text-foreground">
            Model
          </label>
          <div className="flex flex-wrap gap-2">
            <input
              id={`${provider.provider}-model`}
              className={cn(FIELD, "max-w-sm flex-1")}
              placeholder="gpt-4o-mini"
              {...modelForm.register("model")}
            />
            <Button type="submit" size="sm" variant="outline" disabled={busy}>
              Save model
            </Button>
          </div>
          {modelForm.formState.errors.model ? (
            <p className="text-xs text-destructive">{modelForm.formState.errors.model.message}</p>
          ) : null}
        </form>
      ) : null}

      {/* --- Google Places spend limit --- */}
      {provider.optionKeys.includes("spend_limit_usd") ? (
        <SpendLimitPanel
          provider={provider}
          busy={busy}
          onSave={(value) =>
            configure.mutate({
              provider: provider.provider,
              // THE WHOLE BAG TRAVELS, NOT JUST THE FIELD THAT CHANGED. The server replaces
              // `options` with what it receives, so sending only the limit would silently clear this
              // provider's pricing overrides — a saved budget would quietly start being divided by a
              // different price than the one an admin configured.
              options: { ...currentOptions(provider), spend_limit_usd: value },
            })
          }
        />
      ) : null}

      {/* --- Connection status --- */}
      <div className="flex flex-wrap items-center justify-between gap-3 rounded-lg bg-muted/40 px-3 py-2.5 dark:bg-muted/60">
        <div className="flex flex-col gap-0.5">
          <span className="flex items-center gap-2 text-sm text-foreground">
            <TestStatusChip provider={provider} />
          </span>
          {provider.lastTestedAt ? (
            <span className="text-xs text-muted-foreground">
              Last checked {new Date(provider.lastTestedAt).toLocaleString()}
            </span>
          ) : null}
        </div>
        <Button
          type="button"
          size="sm"
          variant="outline"
          // Disabled without a key: the server would answer `not_configured` without calling out,
          // so offering the button would invite an action that cannot succeed.
          disabled={busy || !provider.hasApiKey}
          onClick={() => test.mutate(provider.provider)}
        >
          {test.isPending ? "Testing…" : "Test connection"}
        </Button>
      </div>

      <ConfirmDialog
        open={confirmRemove}
        onOpenChange={setConfirmRemove}
        title="Remove this API key?"
        description={`${provider.label} will stop being available for discovery until a new key is saved. OpenStreetMap keeps running in the meantime.`}
        confirmLabel="Remove key"
        destructive
        loading={clear.isPending}
        onConfirm={() => {
          clear.mutate(provider.provider, { onSuccess: () => setConfirmRemove(false) });
        }}
      />
    </div>
  );
}

function TestStatusChip({ provider }: { provider: NerbyProviderConfig }) {
  const status = provider.lastTestStatus;

  if (status === "ok") {
    return (
      <Badge variant="secondary" className="gap-1">
        <CheckCircle2 className="size-3 text-teal-700 dark:text-teal-400" aria-hidden />
        {testStatusLabel(status)}
      </Badge>
    );
  }

  if (status === "auth_failed" || status === "unreachable" || status === "rate_limited") {
    return (
      <Badge variant="outline" className="gap-1 border-amber-300 text-amber-800 dark:border-amber-800 dark:text-amber-300">
        <AlertTriangle className="size-3" aria-hidden />
        {testStatusLabel(status)}
      </Badge>
    );
  }

  // Never tested, no key, or not applicable — stated plainly rather than dressed as a status.
  return <Badge variant="outline">{testStatusLabel(status)}</Badge>;
}


/**
 * What the AI provider can do today, what it needs, and what has yet to be proven — as three
 * separate tiers.
 *
 * Structured as data rather than prose on purpose. The temptation in a settings page is to describe
 * planned capabilities as though they were features; splitting them into "available", "requires a
 * key" and "to be measured" makes an unproven claim impossible to write by accident.
 */
const CAPABILITY_TIERS: { title: string; tone: string; items: string[] }[] = [
  {
    title: "Available now",
    tone: "text-teal-800 dark:text-teal-300",
    items: [
      "Provider architecture and switching",
      "Secure server-side credential storage",
      "Admin-only configuration",
    ],
  },
  {
    title: "Requires an API key",
    tone: "text-amber-800 dark:text-amber-300",
    items: [
      "Broader business and niche discovery",
      "Natural-language discovery",
      "Related and subcategory discovery",
      "Candidate expansion beyond strict OpenStreetMap tags",
      "Possible website and business information enrichment",
    ],
  },
  {
    title: "To be measured",
    tone: "text-muted-foreground",
    items: [
      "Valid businesses found",
      "Useful candidates",
      "Qualified leads",
      "Convertible leads, at acceptable cost",
    ],
  },
];

function CapabilityTiers() {
  return (
    <section className="rounded-lg border border-border-subtle bg-muted/20 p-4 dark:border-border dark:bg-muted/40">
      <h4 className="text-sm font-semibold text-foreground">AI-powered discovery</h4>
      <p className="mt-1 text-xs text-muted-foreground">
        These capabilities are potential advantages, not measured ones. No provider comparison has
        been run yet and OpenStreetMap remains the baseline. The success metric is not how many names
        AI generates — it is valid → useful → qualified → convertible leads at acceptable cost.
      </p>
      {/* The distinction that matters commercially AND for trust: a provider supplies FACTS, it does
          not supply judgements. Better facts may lead the existing scorer to a different answer; the
          scorer's rules never move, and no provider can hand the CRM a score or a priority. */}
      <p className="mt-2 rounded-lg bg-muted/50 px-3 py-2 text-xs text-muted-foreground dark:bg-muted/70">
        <span className="font-medium text-foreground">No provider sets Lead Score or Priority.</span>{" "}
        Discovery supplies candidate and fit data only, which is deterministically verified before a
        lead exists. The existing scoring rules — formulas, weights and caps — are unchanged and are
        the only thing that decides a score. Better verified data may lead those unchanged rules to a
        different result; it never changes the rules, and AI never assigns High, Medium or Low.
      </p>
      <div className="mt-3 grid gap-4 sm:grid-cols-3">
        {CAPABILITY_TIERS.map((tier) => (
          <div key={tier.title}>
            <p className={cn("text-xs font-semibold uppercase tracking-[0.06em]", tier.tone)}>
              {tier.title}
            </p>
            <ul className="mt-1.5 flex flex-col gap-1">
              {tier.items.map((item) => (
                <li key={item} className="text-xs text-muted-foreground">
                  {item}
                </li>
              ))}
            </ul>
          </div>
        ))}
      </div>
    </section>
  );
}

/**
 * The provider's stored options as a plain string map, so a save can resend everything it is not
 * changing. Nulls are dropped: the server treats an absent key and a null one the same way, and
 * sending null back would be asking it to store an absence.
 */
function currentOptions(provider: NerbyProviderConfig): Record<string, string> {
  return Object.fromEntries(
    Object.entries(provider.options ?? {}).filter(
      (entry): entry is [string, string] => typeof entry[1] === "string",
    ),
  );
}

/** USD, because this is what Google charges Vite SEO — not platform money, which is pesos. */
const usd = new Intl.NumberFormat("en-US", {
  style: "currency",
  currency: "USD",
  maximumFractionDigits: 2,
});

const count = new Intl.NumberFormat("en-US");

/**
 * The Google Places spend guardrail: what the budget is, what it has bought, and how to change it.
 *
 * THE NUMBER HERE IS THE ENFORCEMENT, NOT A DISPLAY. Saving this field changes what the server
 * refuses on the very next request — there is no deploy, no restart and no second place to update.
 * That is the whole point of the field, so the copy says it rather than leaving an admin to wonder
 * whether a settings value is wired to anything.
 *
 * BOTH UNITS, ALWAYS. Dollars are a model (a budget divided by a configured price per 1,000
 * requests); requests are what is actually counted. Showing only dollars would hide a stale price
 * inside the very figure it distorts, so the request allowance sits beside it as a cross-check.
 *
 * THREE STATES WORTH DISTINGUISHING, and the card is explicit about each because they look alike
 * and mean opposite things:
 *   - no limit  -> every request allowed. Today's behaviour, and the one state worth warning about.
 *   - a limit   -> enforced; the meter shows how much of it is left.
 *   - zero      -> every Google Places request refused, including the free ones. A deliberate stop.
 */
function SpendLimitPanel({
  provider,
  busy,
  onSave,
}: {
  provider: NerbyProviderConfig;
  busy: boolean;
  onSave: (value: string) => void;
}) {
  const spend = provider.spend;

  const form = useForm<z.infer<typeof spendSchema>>({
    resolver: zodResolver(spendSchema),
    defaultValues: { spendLimitUsd: (provider.options?.spend_limit_usd as string | undefined) ?? "" },
  });

  if (!spend) return null;

  const stopped = spend.limitUsd === 0;
  const tone = spend.unreadable
    ? "destructive"
    : spend.unlimited
      ? "warning"
      : stopped
        ? "destructive"
        : "ok";

  return (
    <div className="flex flex-col gap-3">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <label
          htmlFor={`${provider.provider}-spend-limit`}
          className="flex items-center gap-1.5 text-sm font-medium text-foreground"
        >
          <Wallet className="size-3.5 text-muted-foreground" aria-hidden />
          Monthly spend limit
        </label>
        <Badge
          variant={tone === "ok" ? "secondary" : "outline"}
          className={cn(
            "text-[11px]",
            tone === "warning" && "border-amber-500/40 text-amber-700 dark:text-amber-400",
            tone === "destructive" && "border-destructive/40 text-destructive",
          )}
        >
          {spend.unreadable
            ? "Unreadable — requests refused"
            : spend.unlimited
              ? "No limit set"
              : stopped
                ? "Stopped"
                : `Enforced · ${spend.period}`}
        </Badge>
      </div>

      {/* --- The meter --- */}
      <dl className="grid grid-cols-2 gap-x-4 gap-y-2.5 rounded-lg bg-muted/40 px-3 py-3 sm:grid-cols-4 dark:bg-muted/60">
        <div className="flex flex-col gap-0.5">
          <dt className="text-[11px] uppercase tracking-wide text-muted-foreground">Limit</dt>
          <dd className="text-sm font-medium text-foreground">
            {spend.limitUsd === null ? "None" : usd.format(spend.limitUsd)}
          </dd>
        </div>
        <div className="flex flex-col gap-0.5">
          <dt className="text-[11px] uppercase tracking-wide text-muted-foreground">Used</dt>
          <dd className="text-sm font-medium text-foreground">{usd.format(spend.billedUsd)}</dd>
        </div>
        <div className="flex flex-col gap-0.5">
          <dt className="text-[11px] uppercase tracking-wide text-muted-foreground">Remaining</dt>
          <dd className="text-sm font-medium text-foreground">
            {spend.remainingUsd === null ? "—" : usd.format(spend.remainingUsd)}
          </dd>
        </div>
        <div className="flex flex-col gap-0.5">
          <dt className="text-[11px] uppercase tracking-wide text-muted-foreground">Requests</dt>
          <dd className="text-sm font-medium text-foreground">
            {spend.requestsAllowed === null
              ? count.format(spend.requestsUsed)
              : `${count.format(spend.requestsUsed)} / ${count.format(spend.requestsAllowed)}`}
          </dd>
        </div>
      </dl>

      {/* --- The dial --- */}
      <form
        className="flex flex-col gap-2"
        onSubmit={form.handleSubmit(({ spendLimitUsd }) => onSave(spendLimitUsd.trim()))}
      >
        <div className="flex flex-wrap items-center gap-2">
          <div className="relative flex-1 sm:max-w-[12rem]">
            <span className="pointer-events-none absolute inset-y-0 left-3 flex items-center text-sm text-muted-foreground">
              $
            </span>
            <input
              id={`${provider.provider}-spend-limit`}
              inputMode="decimal"
              className={cn(FIELD, "w-full pl-7")}
              placeholder="No limit"
              aria-describedby={`${provider.provider}-spend-help`}
              {...form.register("spendLimitUsd")}
            />
          </div>
          <Button type="submit" size="sm" variant="outline" disabled={busy}>
            Save limit
          </Button>
          <span className="text-xs text-muted-foreground">USD per calendar month</span>
        </div>

        {form.formState.errors.spendLimitUsd ? (
          <p className="text-xs text-destructive">{form.formState.errors.spendLimitUsd.message}</p>
        ) : null}

        <p id={`${provider.provider}-spend-help`} className="text-xs text-muted-foreground">
          Enforced on every Google Places request — a change takes effect immediately, with no
          deployment. Leave blank for no limit; enter <span className="font-medium">0</span> to stop
          all Google Places requests. OpenStreetMap discovery is free and is never affected.
        </p>

        {spend.listPricePer1000Usd ? (
          <p className="text-xs text-muted-foreground">
            Priced at {usd.format(spend.listPricePer1000Usd)} per 1,000 requests
            {spend.freeTierRequests > 0
              ? `, with the first ${count.format(spend.freeTierRequests)} each month free`
              : ""}
            .
            {spend.effectivePricePer1000Usd &&
            spend.effectivePricePer1000Usd !== spend.listPricePer1000Usd
              ? ` A safety margin is applied, so the allowance is calculated at ${usd.format(
                  spend.effectivePricePer1000Usd,
                )} per 1,000.`
              : ""}
          </p>
        ) : null}

        {spend.unlimited ? (
          <p className="flex items-start gap-1.5 text-xs text-amber-700 dark:text-amber-400">
            <AlertTriangle className="mt-0.5 size-3.5 shrink-0" aria-hidden />
            No spend limit is set, so Google Places requests are not capped by this CRM.
          </p>
        ) : null}

        {stopped ? (
          <p className="flex items-start gap-1.5 text-xs text-destructive">
            <AlertTriangle className="mt-0.5 size-3.5 shrink-0" aria-hidden />
            Every Google Places request is being refused. Raise the limit or clear it to resume.
          </p>
        ) : null}

        {spend.unreadable ? (
          <p className="flex items-start gap-1.5 text-xs text-destructive">
            <AlertTriangle className="mt-0.5 size-3.5 shrink-0" aria-hidden />
            The stored limit is not a readable amount, so requests are being refused. Save a valid
            amount to resume.
          </p>
        ) : null}
      </form>
    </div>
  );
}
