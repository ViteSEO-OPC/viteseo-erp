"use client";

import { useState } from "react";
import { useForm } from "react-hook-form";
import { zodResolver } from "@hookform/resolvers/zod";
import { z } from "zod";
import { AlertTriangle, CheckCircle2, Lock, PhoneCall } from "lucide-react";
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
  fieldLabel,
  testStatusLabel,
  useCallingIntegration,
  useClearCallingCredentials,
  useConfigureCalling,
  useTestCallingConnection,
  type CallingIntegration,
} from "@/modules/crm/features/settings/hooks/use-calling-integration";

const cardClass = "border border-border bg-card p-6 shadow-card";

/**
 * Settings → Integrations → Calling.
 *
 * ── Zerem: centrally managed ──
 * Zerem places browser calls through ONE centralized ViteSEO Twilio account configured on the server
 * (see the backend's TwilioConfig). When the API says `centrallyManaged` — Zerem's configuration —
 * this card is a read-only STATUS: whether browser calling is on, and that manual calling carries on
 * when it is not. It offers no credential form, because nobody here enters Twilio credentials, and it
 * never shows a secret. Everything below describes the per-organization form, which renders only if a
 * deployment explicitly switches per-organization accounts on.
 *
 * ── The distinction this card exists to make legible ──
 * CONFIGURED IS NOT RUNNING. Browser calling needs three things at once — the switch on, every field
 * filled, and Twilio's own confirmation that the credentials work — so an admin can have saved a
 * complete configuration and still, correctly, not have the feature. Four states, in the order they
 * are reached, and only the last means a BD can call from the browser:
 *
 *   Not configured → Saved → Verified by Twilio → Browser calling on
 *
 * The third step is not ceremony. Seven well-formed strings prove only that seven fields were filled
 * in; without Twilio's verdict the CRM would offer a BD a "Call from Browser" button that could only
 * fail at the carrier. `StateExplanation` below always names which of the three is missing.
 *
 * The header chip is computed from what the SERVER says is in force, never from the form's contents,
 * so a half-filled or unverified configuration can never read as a working one.
 *
 * ── It never holds a secret ──
 * The API sends `hasAuthToken` / `hasApiSecret` and the last four characters, and nothing else. Both
 * secret inputs are therefore write-only: they start empty, show a masked placeholder when something
 * is stored, and are never populated from a response. Leaving one blank on save keeps what is stored.
 *
 * ── Manual calling is not affected by anything here ──
 * Turning this off, misconfiguring it, or never touching it leaves the CRM's free calling workflow
 * exactly as it is. That is stated on the card, because an admin looking at an integration screen
 * should not have to wonder whether they are about to break calling for the whole team.
 */
export function TwilioCallingCard() {
  const { integration, isLoading, isError, isForbidden, retry } = useCallingIntegration();

  return (
    <Card className={cardClass}>
      <div className="flex flex-col gap-5">
        <header className="flex flex-wrap items-start justify-between gap-4">
          <div className="flex items-start gap-3">
            <span className="mt-0.5 flex size-9 items-center justify-center rounded-lg bg-muted">
              <PhoneCall className="size-4 text-teal-700 dark:text-teal-400" aria-hidden />
            </span>
            <div>
              <h3 className="text-base font-semibold text-foreground">Calling</h3>
              <p className="mt-0.5 text-sm text-muted-foreground">
                {integration?.centrallyManaged
                  ? "Browser calling through ViteSEO's centrally managed Twilio account."
                  : "Optional. Connect a Twilio account to let BDs call leads from the browser."}
              </p>
            </div>
          </div>
          {integration ? <StatusChip integration={integration} /> : null}
        </header>

        {isLoading ? (
          <div className="flex flex-col gap-3" aria-busy>
            <Skeleton className="h-16 w-full" />
            <Skeleton className="h-16 w-full" />
          </div>
        ) : isForbidden ? (
          <p className="rounded-lg border border-border bg-muted/40 p-4 text-sm text-muted-foreground">
            Only an Admin can change the calling configuration.
          </p>
        ) : isError || !integration ? (
          <ErrorState
            message="Could not load the calling configuration."
            onRetry={() => void retry()}
          />
        ) : integration.centrallyManaged ? (
          <CentralStatus integration={integration} />
        ) : (
          <Body integration={integration} />
        )}
      </div>
    </Card>
  );
}

/** What is actually in force, in one chip. Computed from the server's answer, never from the form. */
function StatusChip({ integration }: { integration: CallingIntegration }) {
  if (integration.browserCalling) {
    return (
      <Badge className="gap-1 bg-teal-600 text-white dark:bg-teal-700">
        <CheckCircle2 className="size-3" aria-hidden />
        Browser calling on
      </Badge>
    );
  }

  // Centrally managed, the org's own stored row is not what runs, so it cannot make this amber.
  if (!integration.centrallyManaged && (integration.settings.configured || integration.enabled)) {
    return (
      <Badge
        variant="outline"
        className="gap-1 border-amber-300 bg-amber-100 text-amber-800 dark:border-amber-800 dark:bg-amber-950/50 dark:text-amber-300"
      >
        <AlertTriangle className="size-3" aria-hidden />
        Not running
      </Badge>
    );
  }

  return (
    <Badge variant="outline" className="text-muted-foreground">
      Manual calling
    </Badge>
  );
}

function Body({ integration }: { integration: CallingIntegration }) {
  const configure = useConfigureCalling();
  const clear = useClearCallingCredentials();
  const test = useTestCallingConnection();
  const [confirmRemove, setConfirmRemove] = useState(false);

  const busy = configure.isPending || clear.isPending || test.isPending;
  const { settings } = integration;

  return (
    <>
      {/* The reassurance an admin actually needs on this screen, stated before any input. */}
      <p className="rounded-lg bg-muted/40 px-3 py-2 text-xs text-muted-foreground">
        Calling works without this. With no Twilio account your team keeps dialing from their own
        phones and logging the outcome in the CRM — connecting one only adds the option to call from
        the browser, with the talk time measured by the carrier.
      </p>

      <StateExplanation integration={integration} />

      <div className="flex flex-wrap items-center justify-between gap-3">
        <div>
          <p className="text-sm font-medium text-foreground">Enable browser calling</p>
          <p className="text-xs text-muted-foreground">
            Saving credentials does not switch this on — enabling is a separate step.
          </p>
        </div>
        <Switch
          checked={settings.enabled}
          disabled={busy}
          aria-label="Enable browser calling"
          onCheckedChange={(checked) => configure.mutate({ enabled: checked })}
        />
      </div>

      <CredentialsForm integration={integration} busy={busy} />

      {/* --- Connection status --- */}
      <div className="flex flex-wrap items-center justify-between gap-3 rounded-lg bg-muted/40 px-3 py-2.5 dark:bg-muted/60">
        <div className="flex flex-col gap-0.5">
          <span className="flex items-center gap-2 text-sm text-foreground">
            <TestStatusChip integration={integration} />
          </span>
          {settings.lastTestedAt ? (
            <span className="text-xs text-muted-foreground">
              Last checked {new Date(settings.lastTestedAt).toLocaleString()}
            </span>
          ) : null}
        </div>
        <div className="flex flex-wrap gap-2">
          <Button
            type="button"
            size="sm"
            // Promoted to the primary action when a verified test is the ONLY thing standing between
            // this workspace and working browser calling — which, after any credential edit, it is.
            variant={integration.complete && !integration.verified ? "default" : "outline"}
            // Disabled while incomplete: the server would answer `not_configured` without calling
            // out, so offering the button would invite an action that cannot succeed.
            disabled={busy || !integration.complete}
            onClick={() => test.mutate()}
          >
            {test.isPending ? "Testing…" : "Test connection"}
          </Button>
          {settings.hasAuthToken || settings.hasApiSecret ? (
            <Button
              type="button"
              size="sm"
              variant="outline"
              disabled={busy}
              onClick={() => setConfirmRemove(true)}
            >
              Remove credentials
            </Button>
          ) : null}
        </div>
      </div>

      <p className="text-xs text-muted-foreground">
        A connection test only reads your Twilio account to check the credentials — it never places a
        call and never rings a number. Browser calling stays off until a test succeeds, and any change
        to a credential means testing again.
      </p>

      <ConfirmDialog
        open={confirmRemove}
        onOpenChange={setConfirmRemove}
        title="Remove the Twilio credentials?"
        description="Browser calling stops until a new Auth Token and API Key Secret are saved. Your team keeps calling from their own phones and logging outcomes in the CRM, exactly as they do now."
        confirmLabel="Remove credentials"
        destructive
        loading={clear.isPending}
        onConfirm={() => clear.mutate(undefined, { onSuccess: () => setConfirmRemove(false) })}
      />
    </>
  );
}

/**
 * Zerem's card: the centrally managed ViteSEO account, as a status. No form, no switch, no test and no
 * secret — Twilio is configured by ViteSEO on the server, and nobody here enters credentials.
 */
function CentralStatus({ integration }: { integration: CallingIntegration }) {
  return (
    <>
      {integration.browserCalling ? (
        <div
          role="status"
          className="flex items-start gap-2 rounded-lg border border-teal-600/40 bg-teal-50/50 px-3 py-2.5 text-sm text-teal-900 dark:border-teal-700/60 dark:bg-muted/40 dark:text-teal-200"
        >
          <CheckCircle2 className="mt-0.5 size-4 shrink-0" aria-hidden />
          <p>
            Browser calling is on. Calls go through ViteSEO&apos;s centrally configured Twilio account,
            so BDs see “Call from Browser” on any lead with a usable phone number.
          </p>
        </div>
      ) : (
        <div
          role="status"
          className="flex items-start gap-2 rounded-lg border border-border bg-muted/40 px-3 py-2.5 text-sm text-muted-foreground"
        >
          <PhoneCall className="mt-0.5 size-4 shrink-0" aria-hidden />
          <p>
            Browser calling is not available right now, so your team calls from their own phones and
            logs the outcome in the CRM — nothing here needs to change. It becomes available once
            ViteSEO&apos;s central Twilio account is configured on the server.
          </p>
        </div>
      )}

      <p className="text-xs text-muted-foreground">
        Twilio is configured centrally by ViteSEO on the server. Individual users and workspaces do not
        enter their own Twilio credentials, and no Twilio secret is ever shown on this page.
      </p>
    </>
  );
}

/**
 * Why browser calling is or is not running, in one sentence.
 *
 * Each case names what to DO, because "not running" without a reason is the state an admin cannot
 * get out of. The missing-field list comes from the server, so it can never disagree with what the
 * server would accept.
 */
function StateExplanation({ integration }: { integration: CallingIntegration }) {
  if (integration.browserCalling) {
    const inherited = integration.source === "deployment";
    return (
      <div
        role="status"
        className="flex items-start gap-2 rounded-lg border border-teal-600/40 bg-teal-50/50 px-3 py-2.5 text-sm text-teal-900 dark:border-teal-700/60 dark:bg-muted/40 dark:text-teal-200"
      >
        <CheckCircle2 className="mt-0.5 size-4 shrink-0" aria-hidden />
        <p>
          {inherited
            ? "Browser calling is running on the credentials configured for this deployment. Save your own Twilio details below to use your own account instead."
            : "Browser calling is running on your Twilio account. BDs see “Call from Browser” on any lead with a usable phone number."}
        </p>
      </div>
    );
  }

  if (!integration.settings.configured && integration.source === "none") {
    return null; // Nothing configured and nothing claimed — the form below is the whole message.
  }

  // In the order an admin has to resolve them. "Not verified" is last because it is the only one
  // that needs Twilio to answer, and it is the state a fresh save always lands in.
  const reason = !integration.complete
    ? `Still needed: ${integration.missing.map(fieldLabel).join(", ")}.`
    : !integration.enabled
      ? "The switch above is off."
      : integration.settings.lastTestStatus && integration.settings.lastTestStatus !== "ok"
        ? `${testStatusLabel(integration.settings.lastTestStatus)} — correct the details above and test again.`
        : "Run Test connection to confirm the credentials — browser calling starts as soon as Twilio accepts them.";

  return (
    <div
      role="status"
      className="flex items-start gap-2 rounded-lg border border-amber-300 bg-amber-50 px-3 py-2.5 text-sm text-amber-900 dark:border-amber-700/60 dark:bg-amber-950/20 dark:text-amber-200"
    >
      <AlertTriangle className="mt-0.5 size-4 shrink-0" aria-hidden />
      <p>
        Browser calling is not running, so your team is calling from their own phones. {reason}
      </p>
    </div>
  );
}

/**
 * The form. Identifiers and secrets are submitted together, but only the secrets are write-only —
 * an empty secret field means "keep what is stored", which is why neither is ever pre-filled and both
 * show a masked hint instead.
 *
 * The shapes are validated here exactly as the server validates them, so a paste error is caught
 * before a round trip rather than after one.
 */
const schema = z.object({
  account_sid: z
    .string()
    .trim()
    .regex(/^AC[0-9a-fA-F]{32}$/, "An Account SID starts with AC and is 34 characters long."),
  api_key: z
    .string()
    .trim()
    .regex(/^SK[0-9a-fA-F]{32}$/, "An API Key SID starts with SK and is 34 characters long."),
  twiml_app_sid: z
    .string()
    .trim()
    .regex(/^AP[0-9a-fA-F]{32}$/, "A TwiML App SID starts with AP and is 34 characters long."),
  caller_id: z
    .string()
    .trim()
    .regex(/^\+[1-9]\d{7,14}$/, "Use the full international form, e.g. +639171234567."),
  webhook_base_url: z
    .string()
    .trim()
    .url("Enter the full https address Twilio should call back on.")
    .startsWith("https://", "Twilio will not post call data over http."),
  auth_token: z
    .string()
    .trim()
    .regex(/^\S*$/, "An Auth Token cannot contain spaces.")
    .refine((v) => v === "" || v.length >= 20, "Paste the full Auth Token."),
  api_secret: z
    .string()
    .trim()
    .regex(/^\S*$/, "An API Key Secret cannot contain spaces.")
    .refine((v) => v === "" || v.length >= 20, "Paste the full API Key Secret."),
});

type FormValues = z.infer<typeof schema>;

function CredentialsForm({
  integration,
  busy,
}: {
  integration: CallingIntegration;
  busy: boolean;
}) {
  const configure = useConfigureCalling();
  const { settings } = integration;

  const form = useForm<FormValues>({
    resolver: zodResolver(schema),
    defaultValues: {
      // Identifiers ARE pre-filled: they are not secrets, and an admin correcting one should not have
      // to retype the other four.
      account_sid: settings.accountSid ?? "",
      api_key: settings.apiKey ?? "",
      twiml_app_sid: settings.twimlAppSid ?? "",
      caller_id: settings.callerId ?? "",
      webhook_base_url: settings.webhookBaseUrl ?? "",
      // Secrets never are.
      auth_token: "",
      api_secret: "",
    },
  });

  const submit = form.handleSubmit((values) => {
    // An untouched secret field is omitted entirely rather than sent as an empty string, which is
    // what makes "leave blank to keep the stored value" true at the API rather than only in the UI.
    const payload = {
      account_sid: values.account_sid,
      api_key: values.api_key,
      twiml_app_sid: values.twiml_app_sid,
      caller_id: values.caller_id,
      webhook_base_url: values.webhook_base_url,
      ...(values.auth_token ? { auth_token: values.auth_token } : {}),
      ...(values.api_secret ? { api_secret: values.api_secret } : {}),
    };

    configure.mutate(payload, {
      onSuccess: () => {
        // Clear the two write-only inputs so a saved secret is never left sitting in the DOM, and so
        // the placeholder goes back to being the only indication of what is stored.
        form.resetField("auth_token");
        form.resetField("api_secret");
      },
    });
  });

  return (
    <form className="flex flex-col gap-4 border-t border-border-subtle pt-4" onSubmit={submit}>
      <div className="grid gap-4 sm:grid-cols-2">
        <Field
          id="twilio-account-sid"
          label="Account SID"
          hint="Twilio Console → Account Info"
          placeholder="AC…"
          error={form.formState.errors.account_sid?.message}
          {...form.register("account_sid")}
        />
        <SecretField
          id="twilio-auth-token"
          label="Auth Token"
          hint="Validates Twilio's webhooks. Never leaves the server."
          stored={settings.hasAuthToken}
          last4={settings.authTokenLast4}
          error={form.formState.errors.auth_token?.message}
          {...form.register("auth_token")}
        />
        <Field
          id="twilio-api-key"
          label="API Key SID"
          hint="Twilio Console → Account → API keys & tokens"
          placeholder="SK…"
          error={form.formState.errors.api_key?.message}
          {...form.register("api_key")}
        />
        <SecretField
          id="twilio-api-secret"
          label="API Key Secret"
          hint="Shown by Twilio only once, when the key is created."
          stored={settings.hasApiSecret}
          last4={settings.apiSecretLast4}
          error={form.formState.errors.api_secret?.message}
          {...form.register("api_secret")}
        />
        <Field
          id="twilio-twiml-app"
          label="TwiML App SID"
          hint="The Voice app whose request URL points at this CRM."
          placeholder="AP…"
          error={form.formState.errors.twiml_app_sid?.message}
          {...form.register("twiml_app_sid")}
        />
        <Field
          id="twilio-caller-id"
          label="Caller ID"
          hint="The Twilio number the person you call will see."
          placeholder="+639171234567"
          error={form.formState.errors.caller_id?.message}
          {...form.register("caller_id")}
        />
        <div className="sm:col-span-2">
          <Field
            id="twilio-webhook-url"
            label="Callback URL"
            hint="The public https address Twilio can reach this CRM on. Set the same value as your TwiML App's request URL."
            placeholder="https://crm.example.com"
            error={form.formState.errors.webhook_base_url?.message}
            {...form.register("webhook_base_url")}
          />
        </div>
      </div>

      <div className="flex flex-wrap items-center gap-3">
        <Button type="submit" size="sm" disabled={busy}>
          {configure.isPending ? "Saving…" : "Save"}
        </Button>
        <p className="text-xs text-muted-foreground">
          Secrets are encrypted before they are stored and are never sent back to this page. Leave one
          blank to keep the value already saved.
        </p>
      </div>
    </form>
  );
}

type FieldProps = {
  id: string;
  label: string;
  hint: string;
  placeholder?: string;
  error?: string;
} & React.ComponentPropsWithoutRef<"input">;

function Field({ id, label, hint, error, className, ...input }: FieldProps) {
  return (
    <div className="flex flex-col gap-1.5">
      <label htmlFor={id} className="text-sm font-medium text-foreground">
        {label}
      </label>
      <input id={id} autoComplete="off" className={cn(FIELD, className)} {...input} />
      <p className="text-xs text-muted-foreground">{hint}</p>
      {error ? <p className="text-xs text-destructive">{error}</p> : null}
    </div>
  );
}

/** A write-only field: never pre-filled, and the placeholder is the only hint at what is stored. */
function SecretField({
  id,
  label,
  hint,
  stored,
  last4,
  error,
  ...input
}: FieldProps & { stored: boolean; last4: string | null }) {
  return (
    <div className="flex flex-col gap-1.5">
      <label htmlFor={id} className="flex items-center gap-1.5 text-sm font-medium text-foreground">
        <Lock className="size-3.5 text-muted-foreground" aria-hidden />
        {label}
      </label>
      <input
        id={id}
        type="password"
        autoComplete="off"
        className={FIELD}
        placeholder={stored ? `••••••••${last4 ?? ""}` : "Paste the value"}
        {...input}
      />
      <p className="text-xs text-muted-foreground">
        {stored ? "Saved. Leave blank to keep it. " : ""}
        {hint}
      </p>
      {error ? <p className="text-xs text-destructive">{error}</p> : null}
    </div>
  );
}

function TestStatusChip({ integration }: { integration: CallingIntegration }) {
  const status = integration.settings.lastTestStatus;

  if (status === "ok") {
    return (
      <Badge variant="secondary" className="gap-1">
        <CheckCircle2 className="size-3 text-teal-700 dark:text-teal-400" aria-hidden />
        {testStatusLabel(status)}
      </Badge>
    );
  }

  if (status && status !== "not_configured") {
    return (
      <Badge
        variant="outline"
        className="gap-1 border-amber-300 text-amber-800 dark:border-amber-800 dark:text-amber-300"
      >
        <AlertTriangle className="size-3" aria-hidden />
        {testStatusLabel(status)}
      </Badge>
    );
  }

  // Never tested, or tested with nothing to test — stated plainly rather than dressed as a status.
  return <Badge variant="outline">{testStatusLabel(status)}</Badge>;
}
