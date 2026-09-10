"use client";

import { useState } from "react";
import { useForm } from "react-hook-form";
import { zodResolver } from "@hookform/resolvers/zod";
import { z } from "zod";
import { AlertTriangle, CheckCircle2, Lock, Radar, Sparkles } from "lucide-react";
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
  tone: "active" | "ready" | "pending" | "idle";
  hint: string;
};

function providerState(
  provider: NerbyProviderConfig,
  integration: NerbyIntegration,
): ProviderState {
  const isRunning = provider.provider === integration.effectiveProvider;

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

function Body({ integration }: { integration: NerbyIntegration }) {
  return (
    <>
      <EffectiveProviderSummary integration={integration} />

      <div className="flex flex-col gap-3">
        {integration.providers.map((provider) => (
          <ProviderOption
            key={provider.provider}
            provider={provider}
            integration={integration}
          />
        ))}

        {/* ROADMAP ONLY — deliberately hard-coded rather than driven by the API, because Google
            Places is not a provider the backend knows about. Rendering it from a real provider list
            would mean inventing an option that cannot be selected. Disabled, no controls. */}
        <ComingSoonOption
          label="Google Places"
          description="Structured places data with stable Place IDs, coordinates and website fields. Pending an evaluation of Philippine coverage, pricing, quotas and data-persistence licensing."
        />
      </div>

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
}: {
  provider: NerbyProviderConfig;
  integration: NerbyIntegration;
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
              <span className="text-sm font-semibold text-foreground">{provider.label}</span>
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
              {provider.requiresCredentials ? (
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

/** A roadmap entry. No controls, cannot be selected, and not backed by any provider. */
function ComingSoonOption({ label, description }: { label: string; description: string }) {
  return (
    <div className="rounded-lg border border-dashed border-border bg-muted/20 p-4 opacity-70">
      <div className="flex items-start gap-3">
        <input
          type="radio"
          name="nerby-provider"
          className="mt-1 size-4"
          disabled
          checked={false}
          aria-label={`${label} — not yet available`}
        />
        <div>
          <span className="flex flex-wrap items-center gap-2">
            <span className="text-sm font-semibold text-muted-foreground">{label}</span>
            <Badge variant="outline">Coming soon</Badge>
          </span>
          <p className="mt-1 text-xs text-muted-foreground">{description}</p>
        </div>
      </div>
    </div>
  );
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
